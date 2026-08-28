<?php

declare(strict_types=1);

use Rudisang\Mailbox\Storage\MaintenanceLock;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\PartRecord;
use Rudisang\Mailbox\Storage\Schema;
use Rudisang\Mailbox\Support\StoragePaths;

function makeRecord(array $overrides = []): MessageRecord
{
    $id = $overrides['id'] ?? strtoupper(bin2hex(random_bytes(13)));
    $id = str_pad(preg_replace('/[^0-9A-HJKMNP-TV-Z]/', '0', $id), 26, '0');

    return MessageRecord::fromRow(array_merge([
        'seq' => null,
        'id' => $id,
        'captured_at' => '2026-08-28T10:00:00Z',
        'message_id' => 'abc@example.test',
        'raw_sha256' => str_repeat('a', 64),
        'raw_bytes' => 100,
        'mailer' => 'local',
        'parse_status' => 'ok',
        'parse_error' => null,
        'subject' => 'Hello there',
        'from_json' => json_encode([['address' => 'a@example.com', 'name' => 'A']]),
        'to_json' => json_encode([['address' => 'b@example.com', 'name' => '']]),
        'cc_json' => '[]',
        'bcc_json' => '[]',
        'reply_to_json' => '[]',
        'envelope_sender' => 'a@example.com',
        'envelope_recipients_json' => json_encode(['b@example.com']),
        'tags_json' => '[]',
        'metadata_json' => '{}',
        'raw_headers_json' => json_encode([['Subject', 'Hello there']]),
        'has_html' => 1,
        'has_text' => 0,
        'preview_text' => 'Hello there body',
        'search_text' => 'hello there body b@example.com',
        'part_count' => 1,
        'attachment_count' => 0,
        'decoded_bytes' => 10,
        'read_at' => null,
        'namespace' => null,
        'context_json' => '{}',
    ], $overrides));
}

beforeEach(function () {
    $this->paths = new StoragePaths(sys_get_temp_dir().'/mailbox-store-'.bin2hex(random_bytes(4)));
    $this->paths->ensureRoot();
    $this->store = new MessageStore($this->paths, new MaintenanceLock($this->paths->lock()));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->paths->root));
});

it('records the current schema version for a fresh store', function () {
    expect($this->store->schemaVersion())->toBe(Schema::version());
});

it('rebuilds stale schema tables and message directories', function () {
    $pdo = $this->store->pdo();
    $pdo->exec('ALTER TABLE messages ADD COLUMN stale_column TEXT NULL');
    $pdo->exec("UPDATE mailbox_meta SET value = '0' WHERE key = 'schema_version'");
    $stale = $this->paths->messagesDir().DIRECTORY_SEPARATOR.'stale-message';
    mkdir($stale, 0700, true);
    file_put_contents($stale.DIRECTORY_SEPARATOR.'raw.eml', 'stale');

    $rebuilt = new MessageStore($this->paths, new MaintenanceLock($this->paths->lock()));
    $columns = $rebuilt->pdo()->query('PRAGMA table_info(messages)')?->fetchAll() ?: [];

    expect(array_column($columns, 'name'))->not->toContain('stale_column')
        ->and(is_dir($stale))->toBeFalse()
        ->and($rebuilt->schemaVersion())->toBe(Schema::version());
});

it('does not cache a pdo when the schema lock is unavailable', function () {
    $lock = new class($this->paths->lock()) extends MaintenanceLock
    {
        public int $attempts = 0;

        public function exclusive(callable $fn, bool $blocking = true): mixed
        {
            $this->attempts++;

            return null;
        }
    };
    $store = new MessageStore($this->paths, $lock);

    expect(fn () => $store->pdo())
        ->toThrow(RuntimeException::class, 'Mailbox schema could not be initialised (lock unavailable).');
    expect(fn () => $store->pdo())
        ->toThrow(RuntimeException::class, 'Mailbox schema could not be initialised (lock unavailable).')
        ->and($lock->attempts)->toBe(2);
});

it('creates the schema lazily and inserts a message with parts', function () {
    $record = makeRecord();
    $part = PartRecord::fromRow(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'message_id' => $record->id, 'parent_id' => null, 'position' => 0, 'depth' => 0, 'content_type' => 'text/html; charset=utf-8', 'media_type' => 'text', 'media_subtype' => 'html', 'disposition' => null, 'filename' => null, 'content_id' => null, 'charset' => 'utf-8', 'transfer_encoding' => 'quoted-printable', 'decoded_bytes' => 10, 'sha256' => str_repeat('b', 64), 'is_inline' => 0, 'is_attachment' => 0]);

    $seq = $this->store->insert($record, [$part]);
    $found = $this->store->find($record->id);
    $recordWithSeq = $record->withSeq($seq);

    expect($seq)->toBe(1)
        ->and($found)->not->toBeNull()
        ->and($found->subject)->toBe('Hello there')
        ->and($found->from[0]['address'])->toBe('a@example.com')
        ->and($found->rawHeaders[0])->toBe(['Subject', 'Hello there'])
        ->and($recordWithSeq)->not->toBe($record)
        ->and($recordWithSeq->seq)->toBe(1)
        ->and($record->seq)->toBeNull()
        ->and($part->isLeaf())->toBeTrue()
        ->and($this->store->parts($record->id))->toHaveCount(1)
        ->and($this->store->findPart($record->id, $part->id)?->mediaSubtype)->toBe('html')
        ->and($this->store->status(null))->toBe(['seq' => 1, 'total' => 1, 'unread' => 1]);
});

it('lists newest first, filters, searches and counts', function () {
    $this->store->insert(makeRecord(['subject' => 'First', 'search_text' => 'first alpha']), []);
    $this->store->insert(makeRecord(['subject' => 'Second', 'search_text' => 'second beta', 'attachment_count' => 2]), []);
    $this->store->insert(makeRecord(['subject' => 'Third', 'search_text' => 'third gamma', 'parse_status' => 'partial', 'namespace' => 'ns-1']), []);

    expect(array_map(fn ($m) => $m->subject, $this->store->list()))->toBe(['Third', 'Second', 'First'])
        ->and($this->store->list(['q' => 'BETA']))->toHaveCount(1)
        ->and($this->store->list(['attachments' => true])[0]->subject)->toBe('Second')
        ->and($this->store->list(['issues' => true])[0]->subject)->toBe('Third')
        ->and($this->store->list(['namespace' => 'ns-1']))->toHaveCount(1)
        ->and($this->store->status('ns-1'))->toBe(['seq' => 3, 'total' => 1, 'unread' => 1])
        ->and($this->store->count(['q' => 'nothing']))->toBe(0)
        ->and($this->store->list(['limit' => 1, 'offset' => 1])[0]->subject)->toBe('Second')
        ->and($this->store->countSince(1, null))->toBe(2)
        ->and($this->store->countSince(2, 'ns-1'))->toBe(1);
});

it('searches lowercased unicode text with an uppercase query', function () {
    $this->store->insert(makeRecord([
        'subject' => 'Ünïcödé',
        'search_text' => mb_strtolower('Ünïcödé', 'UTF-8'),
    ]), []);

    expect($this->store->list(['q' => 'ÜNÏCÖDÉ']))->toHaveCount(1)
        ->and($this->store->count(['q' => 'ÜNÏCÖDÉ']))->toBe(1);
});

it('treats LIKE wildcard and escape characters as literal search text', function () {
    $this->store->insert(makeRecord(['search_text' => '100% delivered']), []);
    $this->store->insert(makeRecord(['search_text' => 'under_score']), []);
    $this->store->insert(makeRecord(['search_text' => 'path\\segment']), []);
    $this->store->insert(makeRecord(['search_text' => 'plain']), []);

    expect($this->store->list(['q' => '%']))->toHaveCount(1)
        ->and($this->store->list(['q' => '_']))->toHaveCount(1)
        ->and($this->store->list(['q' => '\\']))->toHaveCount(1);
});

it('marks read, deletes rows with their directories and clears everything', function () {
    $a = makeRecord();
    $b = makeRecord();
    $this->store->insert($a, []);
    $this->store->insert($b, []);
    mkdir($this->paths->message($a->id), 0755, true);
    file_put_contents($this->paths->raw($a->id), 'raw');

    $this->store->markRead($a->id, true);
    expect($this->store->find($a->id)->isRead())->toBeTrue()
        ->and($this->store->status(null)['unread'])->toBe(1)
        ->and($this->store->count(['unread' => true]))->toBe(1);

    $this->store->markRead($a->id, false);
    expect($this->store->find($a->id)->isRead())->toBeFalse();

    $this->store->markRead($a->id, true);

    $this->store->delete($a->id);
    expect($this->store->find($a->id))->toBeNull()->and(is_dir($this->paths->message($a->id)))->toBeFalse();

    mkdir($this->paths->partsDir($b->id), 0755, true);
    file_put_contents($this->paths->part($b->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'), 'part');
    $this->store->clear();
    expect($this->store->count())->toBe(0)
        ->and(is_dir($this->paths->message($b->id)))->toBeFalse();
});

it('refuses to delete an invalid id without touching the filesystem', function () {
    $sibling = $this->paths->root.DIRECTORY_SEPARATOR.'protected-sibling';
    mkdir($sibling);

    expect(fn () => $this->store->delete('../protected-sibling'))
        ->toThrow(InvalidArgumentException::class, 'Invalid mailbox identifier.')
        ->and(is_dir($sibling))->toBeTrue();
});

it('answers the maintenance queries', function () {
    $old = makeRecord(['captured_at' => '2020-01-01T00:00:00Z', 'raw_bytes' => 600]);
    $mid = makeRecord(['captured_at' => '2026-01-01T00:00:00Z', 'raw_bytes' => 300]);
    $new = makeRecord(['captured_at' => '2026-08-28T00:00:00Z', 'raw_bytes' => 100]);
    foreach ([$old, $mid, $new] as $r) {
        $this->store->insert($r, []);
    }

    expect($this->store->totals())->toBe(['count' => 3, 'bytes' => 1030])
        ->and($this->store->idsOlderThan('2025-01-01T00:00:00Z'))->toBe([$old->id])
        ->and($this->store->idsBeyondCount(1))->toBe([$old->id, $mid->id])
        ->and($this->store->idsBeyondBytes(450))->toBe([$old->id])
        ->and($this->store->allIds())->toHaveCount(3);
});

it('survives concurrent schema initialisation and inserts from several processes', function () {
    $script = <<<'PHP'
    require $argv[1].'/vendor/autoload.php';
    $paths = new Rudisang\Mailbox\Support\StoragePaths($argv[2]);
    $store = new Rudisang\Mailbox\Storage\MessageStore($paths, new Rudisang\Mailbox\Storage\MaintenanceLock($paths->lock()));
    for ($i = 0; $i < 25; $i++) {
        $id = strtoupper(bin2hex(random_bytes(13)));
        $id = substr(preg_replace('/[^0-9A-HJKMNP-TV-Z]/', '7', $id), 0, 26);
        $store->insert(Rudisang\Mailbox\Storage\MessageRecord::fromRow(['seq' => null, 'id' => $id, 'captured_at' => gmdate('c'), 'message_id' => null, 'raw_sha256' => str_repeat('c', 64), 'raw_bytes' => 1, 'mailer' => null, 'parse_status' => 'ok', 'parse_error' => null, 'subject' => 'p', 'from_json' => '[]', 'to_json' => '[]', 'cc_json' => '[]', 'bcc_json' => '[]', 'reply_to_json' => '[]', 'envelope_sender' => null, 'envelope_recipients_json' => '[]', 'tags_json' => '[]', 'metadata_json' => '{}', 'raw_headers_json' => '[]', 'has_html' => 0, 'has_text' => 0, 'preview_text' => null, 'search_text' => null, 'part_count' => 0, 'attachment_count' => 0, 'decoded_bytes' => 0, 'read_at' => null, 'namespace' => null, 'context_json' => '{}']), []);
    }
    echo "ok";
    PHP;
    $file = $this->paths->root.'/worker.php';
    file_put_contents($file, "<?php\n".$script);
    $procs = [];
    for ($p = 0; $p < 4; $p++) {
        $procs[] = proc_open([PHP_BINARY, $file, dirname(__DIR__, 2), $this->paths->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$p]);
    }
    $outputs = [];
    foreach ($procs as $i => $proc) {
        $outputs[] = stream_get_contents($pipes[$i][1]).stream_get_contents($pipes[$i][2]);
        proc_close($proc);
    }

    expect($outputs)->each->toBe('ok')
        ->and($this->store->count())->toBe(100);
});
