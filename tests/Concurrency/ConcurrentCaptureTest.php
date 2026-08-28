<?php

declare(strict_types=1);

use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\StoragePaths;

it('captures from eight processes concurrently without lost or torn records', function () {
    $root = app(StoragePaths::class)->root;
    $procs = $pipes = [];

    for ($p = 0; $p < 8; $p++) {
        $procs[$p] = proc_open([PHP_BINARY, __DIR__.'/worker.php', $root, '100', 'proc-'.$p], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$p]);
    }

    $outputs = [];

    foreach ($procs as $p => $proc) {
        $outputs[$p] = stream_get_contents($pipes[$p][1]).' '.stream_get_contents($pipes[$p][2]);
        proc_close($proc);
    }

    $store = app(MessageStore::class);

    expect(array_map('trim', $outputs))->each->toBe('done');
    expect($store->count())->toBe(800);

    foreach ($store->allIds() as $id) {
        expect(app(StoragePaths::class)->raw($id))->toBeFile();
    }

    expect(app(Repair::class)->scan())->toBe(['orphan_dirs' => [], 'dangling_rows' => [], 'stale_tmp' => []]);

    for ($p = 0; $p < 8; $p++) {
        expect($store->count(['namespace' => 'proc-'.$p]))->toBe(100);
    }
})->group('concurrency');
