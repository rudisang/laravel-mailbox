<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

use Rudisang\Mailbox\Support\StoragePaths;

final class Repair
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly MessageStore $store,
        private readonly MaintenanceLock $lock,
    ) {}

    /**
     * @return array{orphan_dirs: list<string>, dangling_rows: list<string>, stale_tmp: list<string>}
     */
    public function scan(): array
    {
        // Schema initialization takes the same lock, so complete it before maintenance.
        $this->store->pdo();

        /** @var array{orphan_dirs: list<string>, dangling_rows: list<string>, stale_tmp: list<string>} $result */
        $result = $this->lock->exclusive(fn (): array => $this->scanUnlocked(), true);

        return $result;
    }

    /** @return array{orphan_dirs: int, dangling_rows: int, stale_tmp: int} */
    public function repair(): array
    {
        // Schema initialization takes the same lock, so complete it before maintenance.
        $this->store->pdo();

        /** @var array{orphan_dirs: int, dangling_rows: int, stale_tmp: int} $result */
        $result = $this->lock->exclusive(function (): array {
            $scan = $this->scanUnlocked();

            foreach ($scan['orphan_dirs'] as $id) {
                MessageStore::removeDirectory($this->paths->messagesDir().DIRECTORY_SEPARATOR.$id);
            }

            foreach ($scan['dangling_rows'] as $id) {
                $this->store->delete($id);
            }

            foreach ($scan['stale_tmp'] as $id) {
                MessageStore::removeDirectory($this->paths->tmpDir().DIRECTORY_SEPARATOR.$id);
            }

            return [
                'orphan_dirs' => count($scan['orphan_dirs']),
                'dangling_rows' => count($scan['dangling_rows']),
                'stale_tmp' => count($scan['stale_tmp']),
            ];
        }, true);

        return $result;
    }

    /**
     * @return array{orphan_dirs: list<string>, dangling_rows: list<string>, stale_tmp: list<string>}
     */
    private function scanUnlocked(): array
    {
        $ids = $this->store->allIds();
        $knownIds = array_fill_keys($ids, true);
        $orphanDirs = [];

        foreach ($this->entries($this->paths->messagesDir()) as $entry) {
            if (! isset($knownIds[$entry])) {
                $orphanDirs[] = $entry;
            }
        }

        $danglingRows = [];

        foreach ($ids as $id) {
            if (! is_file($this->paths->raw($id))) {
                $danglingRows[] = $id;
            }
        }

        $staleTmp = [];
        $staleBefore = time() - 600;

        foreach ($this->entries($this->paths->tmpDir()) as $entry) {
            $modifiedAt = @filemtime($this->paths->tmpDir().DIRECTORY_SEPARATOR.$entry);

            if (is_int($modifiedAt) && $modifiedAt < $staleBefore) {
                $staleTmp[] = $entry;
            }
        }

        sort($orphanDirs);
        sort($staleTmp);

        return [
            'orphan_dirs' => $orphanDirs,
            'dangling_rows' => $danglingRows,
            'stale_tmp' => $staleTmp,
        ];
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
    }
}
