<?php

declare(strict_types=1);

use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\StoragePaths;

it('preserves committed capture invariants when a worker is killed', function () {
    $paths = app(StoragePaths::class);
    $process = proc_open([
        PHP_BINARY,
        __DIR__.'/worker.php',
        $paths->root,
        '200',
        'killed-worker',
        (string) (200 * 1024),
        'ready',
    ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    expect(is_resource($process))->toBeTrue();
    expect(trim((string) fgets($pipes[1])))->toBe('ready');

    usleep(random_int(50_000, 400_000));
    $terminated = proc_terminate($process, 9);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($terminated)->toBeTrue()
        ->and($stdout)->not->toContain('done')
        ->and($stderr)->toBe('');

    $store = app(MessageStore::class);

    foreach ($store->allIds() as $id) {
        $record = $store->find($id);

        expect($record)->not->toBeNull();
        clearstatcache(true, $paths->raw($id));
        expect($paths->raw($id))->toBeFile()
            ->and(filesize($paths->raw($id)))->toBe($record->rawBytes);
    }

    $repair = app(Repair::class);
    $scan = $repair->scan();
    expect($scan['dangling_rows'])->toBe([])
        ->and(count($scan['orphan_dirs']))->toBeLessThanOrEqual(1)
        ->and($scan['stale_tmp'])->toBe([]);

    $repair->repair();
    $freshTmp = glob($paths->tmpDir().'/*') ?: [];
    expect(count($freshTmp))->toBeLessThanOrEqual(1);

    foreach ($freshTmp as $path) {
        MessageStore::removeDirectory($path);
    }

    expect($repair->scan())->toBe(['orphan_dirs' => [], 'dangling_rows' => [], 'stale_tmp' => []]);
})->skip(fn (): bool => PHP_OS_FAMILY === 'Windows', 'SIGKILL is not available on Windows.')
    ->group('concurrency');
