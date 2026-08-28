<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Capture\MessageRecorder;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Pruner;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\StoragePaths;

function sendOne(string $subject): void
{
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject($subject)->text('x'));
}

it('prunes by count with one-message slack and removes directories', function () {
    config()->set('mailbox.retention.max_messages', 3);
    $this->app->forgetInstance(Pruner::class);
    $this->app->forgetInstance(MessageRecorder::class);
    Mail::purge('local');

    foreach (range(1, 6) as $i) {
        sendOne("m{$i}");
    }
    $store = app(MessageStore::class);

    expect($store->count())->toBeLessThanOrEqual(4)
        ->and($store->list()[0]->subject)->toBe('m6')
        ->and(count(glob(app(StoragePaths::class)->messagesDir().'/*') ?: []))->toBe($store->count());
});

it('prunes by age', function () {
    sendOne('old');
    $store = app(MessageStore::class);
    $store->pdo()->exec("UPDATE messages SET captured_at = '2020-01-01T00:00:00Z'");
    sendOne('new');

    expect(array_map(fn ($m) => $m->subject, $store->list()))->toBe(['new']);
});

it('prunes by total bytes', function () {
    config()->set('mailbox.retention.max_bytes', 2000);
    $this->app->forgetInstance(Pruner::class);
    $this->app->forgetInstance(MessageRecorder::class);
    Mail::purge('local');

    foreach (range(1, 8) as $i) {
        sendOne('bytes'.$i);
    }

    expect(app(MessageStore::class)->totals()['bytes'])->toBeLessThan(2000 + 1500);
});

it('scans and repairs orphan directories, dangling rows and stale tmp', function () {
    sendOne('keep');
    $paths = app(StoragePaths::class);
    $store = app(MessageStore::class);
    $keep = $store->list()[0]->id;
    mkdir($paths->messagesDir().DIRECTORY_SEPARATOR.'01ORPHAN00000000000000000A', 0755, true);
    mkdir($paths->tmpDir().DIRECTORY_SEPARATOR.'01STALE000000000000000000A', 0755, true);
    touch($paths->tmpDir().DIRECTORY_SEPARATOR.'01STALE000000000000000000A', time() - 3600);
    mkdir($paths->tmpDir().DIRECTORY_SEPARATOR.'01FRESH000000000000000000A', 0755, true);
    sendOne('dangling');
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
