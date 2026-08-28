<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Rudisang\Mailbox\Support\StoragePaths;
use Throwable;

final class MessageStore
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly StoragePaths $paths,
        private readonly MaintenanceLock $lock,
    ) {}

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $this->paths->ensureRoot();
        $pdo = new PDO('sqlite:'.$this->paths->index(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA synchronous = FULL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $this->lock->exclusive(function () use ($pdo): void {
            foreach (Schema::statements() as $sql) {
                $pdo->exec($sql);
            }
        });

        return $this->pdo = $pdo;
    }

    /**
     * @param  list<PartRecord>  $parts
     */
    public function insert(MessageRecord $message, array $parts): int
    {
        return $this->retry(function () use ($message, $parts): int {
            $pdo = $this->pdo();
            $pdo->beginTransaction();

            try {
                $row = $message->toRow();
                $columns = array_keys($row);
                $placeholders = array_map(static fn (string $column): string => ':'.$column, $columns);
                $pdo->prepare('INSERT INTO messages ('.implode(',', $columns).') VALUES ('.implode(',', $placeholders).')')->execute($row);
                $seq = (int) $pdo->lastInsertId();
                $statement = null;

                foreach ($parts as $part) {
                    $partRow = $part->toRow();
                    $partColumns = array_keys($partRow);
                    $partPlaceholders = array_map(static fn (string $column): string => ':'.$column, $partColumns);
                    $statement ??= $pdo->prepare('INSERT INTO parts ('.implode(',', $partColumns).') VALUES ('.implode(',', $partPlaceholders).')');
                    $statement->execute($partRow);
                }

                $pdo->commit();

                return $seq;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }
        });
    }

    public function find(string $id): ?MessageRecord
    {
        $statement = $this->pdo()->prepare('SELECT * FROM messages WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (! is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return MessageRecord::fromRow($row);
    }

    /** @return list<PartRecord> */
    public function parts(string $id): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM parts WHERE message_id = :message_id ORDER BY position ASC');
        $statement->execute(['message_id' => $id]);

        return $this->partRecords($statement);
    }

    public function findPart(string $messageId, string $partId): ?PartRecord
    {
        $statement = $this->pdo()->prepare('SELECT * FROM parts WHERE message_id = :message_id AND id = :id LIMIT 1');
        $statement->execute(['message_id' => $messageId, 'id' => $partId]);
        $row = $statement->fetch();

        if (! is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return PartRecord::fromRow($row);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<MessageRecord>
     */
    public function list(array $filters = []): array
    {
        [$where, $parameters] = $this->where($filters);
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $statement = $this->pdo()->prepare('SELECT * FROM messages'.$where.' ORDER BY seq DESC LIMIT :limit OFFSET :offset');

        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $this->messageRecords($statement);
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters = []): int
    {
        [$where, $parameters] = $this->where($filters);
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM messages'.$where);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @return array{seq: int, total: int, unread: int} */
    public function status(?string $namespace): array
    {
        $sql = 'SELECT COALESCE(MAX(seq), 0) AS seq, COUNT(*) AS total, COALESCE(SUM(read_at IS NULL), 0) AS unread FROM messages';
        $parameters = [];

        if ($namespace !== null) {
            $sql .= ' WHERE namespace = :namespace';
            $parameters['namespace'] = $namespace;
        }

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();

        if (! is_array($row)) {
            return ['seq' => 0, 'total' => 0, 'unread' => 0];
        }

        return [
            'seq' => (int) ($row['seq'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
            'unread' => (int) ($row['unread'] ?? 0),
        ];
    }

    public function countSince(int $seq, ?string $namespace): int
    {
        $sql = 'SELECT COUNT(*) FROM messages WHERE seq > :seq';
        $parameters = ['seq' => $seq];

        if ($namespace !== null) {
            $sql .= ' AND namespace = :namespace';
            $parameters['namespace'] = $namespace;
        }

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function markRead(string $id, bool $read): void
    {
        $statement = $this->pdo()->prepare('UPDATE messages SET read_at = :read_at WHERE id = :id');
        $statement->execute([
            'read_at' => $read ? gmdate('Y-m-d\TH:i:s\Z') : null,
            'id' => $id,
        ]);
    }

    public function delete(string $id): void
    {
        if (! StoragePaths::isValidId($id)) {
            throw new InvalidArgumentException('Invalid mailbox identifier.');
        }

        $statement = $this->pdo()->prepare('DELETE FROM messages WHERE id = :id');
        $statement->execute(['id' => $id]);

        self::removeDirectory($this->paths->message($id));
    }

    public function clear(): void
    {
        $this->pdo()->exec('DELETE FROM messages');
        $entries = @scandir($this->paths->messagesDir());

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::removeDirectory($this->paths->messagesDir().DIRECTORY_SEPARATOR.$entry);
        }
    }

    /** @return list<string> */
    public function allIds(): array
    {
        $statement = $this->pdo()->prepare('SELECT id FROM messages ORDER BY seq ASC');
        $statement->execute();

        return $this->ids($statement);
    }

    /** @return list<string> */
    public function idsOlderThan(string $isoUtc): array
    {
        $statement = $this->pdo()->prepare('SELECT id FROM messages WHERE captured_at < :captured_at ORDER BY seq ASC');
        $statement->execute(['captured_at' => $isoUtc]);

        return $this->ids($statement);
    }

    /** @return list<string> */
    public function idsBeyondCount(int $keep): array
    {
        $statement = $this->pdo()->prepare('SELECT id FROM messages ORDER BY seq DESC LIMIT -1 OFFSET :keep');
        $statement->bindValue(':keep', max(0, $keep), PDO::PARAM_INT);
        $statement->execute();

        return array_reverse($this->ids($statement));
    }

    /** @return list<string> */
    public function idsBeyondBytes(int $maxBytes): array
    {
        $total = $this->totals()['bytes'];
        $statement = $this->pdo()->prepare('SELECT id, raw_bytes, decoded_bytes FROM messages ORDER BY seq ASC');
        $statement->execute();
        $ids = [];

        foreach ($statement->fetchAll() as $row) {
            if ($total <= $maxBytes || ! is_array($row)) {
                break;
            }

            $ids[] = (string) ($row['id'] ?? '');
            $total -= (int) ($row['raw_bytes'] ?? 0) + (int) ($row['decoded_bytes'] ?? 0);
        }

        return $ids;
    }

    /** @return array{count: int, bytes: int} */
    public function totals(): array
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(raw_bytes + decoded_bytes), 0) AS bytes FROM messages');
        $statement->execute();
        $row = $statement->fetch();

        if (! is_array($row)) {
            return ['count' => 0, 'bytes' => 0];
        }

        return [
            'count' => (int) ($row['total'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
        ];
    }

    public static function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = @scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::removeDirectory($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function where(array $filters): array
    {
        $clauses = [];
        $parameters = [];
        $query = $filters['q'] ?? null;

        if (is_string($query) && $query !== '') {
            $clauses[] = "(subject LIKE :q ESCAPE '\\' OR search_text LIKE :q ESCAPE '\\' OR message_id LIKE :q ESCAPE '\\')";
            $parameters['q'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
        }

        if (($filters['unread'] ?? false) === true) {
            $clauses[] = 'read_at IS NULL';
        }

        if (($filters['attachments'] ?? false) === true) {
            $clauses[] = 'attachment_count > 0';
        }

        if (($filters['issues'] ?? false) === true) {
            $clauses[] = "parse_status <> 'ok'";
        }

        $namespace = $filters['namespace'] ?? null;

        if (is_string($namespace)) {
            $clauses[] = 'namespace = :namespace';
            $parameters['namespace'] = $namespace;
        }

        return [$clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses), $parameters];
    }

    /** @return list<MessageRecord> */
    private function messageRecords(PDOStatement $statement): array
    {
        $records = [];

        foreach ($statement->fetchAll() as $row) {
            if (! is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $records[] = MessageRecord::fromRow($row);
        }

        return $records;
    }

    /** @return list<PartRecord> */
    private function partRecords(PDOStatement $statement): array
    {
        $records = [];

        foreach ($statement->fetchAll() as $row) {
            if (! is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $records[] = PartRecord::fromRow($row);
        }

        return $records;
    }

    /** @return list<string> */
    private function ids(PDOStatement $statement): array
    {
        $ids = [];

        while (($id = $statement->fetchColumn()) !== false) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * Retries SQLITE_BUSY / SQLITE_LOCKED with jitter; 3 attempts on top of busy_timeout.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function retry(callable $fn): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $fn();
            } catch (PDOException $exception) {
                $busy = str_contains($exception->getMessage(), 'database is locked')
                    || str_contains($exception->getMessage(), 'database table is locked');

                if (! $busy || ++$attempt >= 3) {
                    throw $exception;
                }

                usleep(random_int(20_000, 120_000));
            }
        }
    }
}
