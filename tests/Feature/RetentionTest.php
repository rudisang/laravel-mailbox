<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Capture\MessageRecorder;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Pruner;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\StoragePaths;

it('prunes by count with one-message slack and removes directories', function () {
    config()->set('mailbox.retention.max_messages', 3);
    $this->app->forgetInstance(Pruner::class);
    $this->app->forgetInstance(MessageRecorder::class);
    Mail::purge('local');

    foreach (range(1, 6) as $i) {
        mailboxSendOne("m{$i}");
    }
    $store = app(MessageStore::class);

    expect($store->count())->toBeGreaterThanOrEqual(3)
        ->toBeLessThanOrEqual(4)
        ->and($store->list()[0]->subject)->toBe('m6')
        ->and(count(glob(app(StoragePaths::class)->messagesDir().'/*') ?: []))->toBe($store->count());
});

it('prunes by age', function () {
    mailboxSendOne('old');
    $store = app(MessageStore::class);
    $store->pdo()->exec("UPDATE messages SET captured_at = '2020-01-01T00:00:00Z'");
    mailboxSendOne('new');

    expect(array_map(fn ($m) => $m->subject, $store->list()))->toBe(['new']);
});

it('prunes by total bytes', function () {
    config()->set('mailbox.retention.max_bytes', 2000);
    $this->app->forgetInstance(Pruner::class);
    $this->app->forgetInstance(MessageRecorder::class);
    Mail::purge('local');

    foreach (range(1, 8) as $i) {
        mailboxSendOne('bytes'.$i);
    }

    $totals = app(MessageStore::class)->totals();

    expect($totals['bytes'])->toBeGreaterThan(0)->toBeLessThan(2000 + 1500)
        ->and($totals['count'])->toBeGreaterThanOrEqual(1);
});

it('scans and repairs orphan directories, dangling rows and stale tmp', function () {
    mailboxSendOne('keep');
    $paths = app(StoragePaths::class);
    $store = app(MessageStore::class);
    $keep = $store->list()[0]->id;
    mkdir($paths->messagesDir().DIRECTORY_SEPARATOR.'01ORPHAN00000000000000000A', 0755, true);
    mkdir($paths->tmpDir().DIRECTORY_SEPARATOR.'01STALE000000000000000000A', 0755, true);
    touch($paths->tmpDir().DIRECTORY_SEPARATOR.'01STALE000000000000000000A', time() - 3600);
    mkdir($paths->tmpDir().DIRECTORY_SEPARATOR.'01FRESH000000000000000000A', 0755, true);
    mailboxSendOne('dangling');
    $dangling = $store->list()[0]->id;
    MessageStore::removeDirectory($paths->message($dangling));

    $repair = app(Repair::class);
    $scan = $repair->scan();
    expect($scan['orphan_dirs'])->toBe(['01ORPHAN00000000000000000A'])
        ->and($scan['dangling_rows'])->toBe([$dangling])
        ->and($scan['stale_tmp'])->toBe(['01STALE000000000000000000A']);

    expect($repair->repair())->toBe(['orphan_dirs' => 1, 'dangling_rows' => 1, 'stale_tmp' => 1])
        ->and($store->find($keep))->not->toBeNull()
        ->and($store->find($dangling))->toBeNull()
        ->and(is_dir($paths->tmpDir().DIRECTORY_SEPARATOR.'01FRESH000000000000000000A'))->toBeTrue()
        ->and($repair->scan())->toBe(['orphan_dirs' => [], 'dangling_rows' => [], 'stale_tmp' => []]);
});

it('reports truncated raw files and missing leaf blobs as dangling rows', function () {
    mailboxSendOne('truncated raw');
    mailboxSendOne('missing leaf');
    $store = app(MessageStore::class);
    $paths = app(StoragePaths::class);
    $records = $store->list();
    $missingLeaf = $records[0];
    $truncatedRaw = $records[1];
    $rawHandle = fopen($paths->raw($truncatedRaw->id), 'c+');

    expect(is_resource($rawHandle))->toBeTrue();
    ftruncate($rawHandle, $truncatedRaw->rawBytes - 1);
    fclose($rawHandle);

    $leaf = array_values(array_filter(
        $store->parts($missingLeaf->id),
        static fn ($part): bool => $part->isLeaf(),
    ))[0];
    unlink($paths->part($missingLeaf->id, $leaf->id));

    $repair = app(Repair::class);

    expect($repair->scan()['dangling_rows'])->toBe([$truncatedRaw->id, $missingLeaf->id])
        ->and($repair->repair()['dangling_rows'])->toBe(2)
        ->and($store->find($truncatedRaw->id))->toBeNull()
        ->and($store->find($missingLeaf->id))->toBeNull();
});

it('ignores stray files in the messages and tmp directories', function () {
    $paths = app(StoragePaths::class);
    $paths->ensureRoot();
    $messagesFile = $paths->messagesDir().DIRECTORY_SEPARATOR.'.DS_Store';
    $tmpFile = $paths->tmpDir().DIRECTORY_SEPARATOR.'notes.txt';

    touch($messagesFile, time() - 3600);
    touch($tmpFile, time() - 3600);

    $repair = app(Repair::class);

    expect($repair->scan())->toBe(['orphan_dirs' => [], 'dangling_rows' => [], 'stale_tmp' => []])
        ->and($repair->repair())->toBe(['orphan_dirs' => 0, 'dangling_rows' => 0, 'stale_tmp' => 0])
        ->and(is_file($messagesFile))->toBeTrue()
        ->and(is_file($tmpFile))->toBeTrue();
});
