<?php

declare(strict_types=1);

use Rudisang\Mailbox\Support\StoragePaths;

it('builds every mailbox storage path for valid identifiers', function () {
    $root = sys_get_temp_dir().'/mailbox-paths';
    $messageId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $partId = '01BX5ZZKBKACTAV9WEVGEMMVRZ';
    $paths = new StoragePaths($root);

    expect($paths->index())->toBe($root.DIRECTORY_SEPARATOR.'index.sqlite')
        ->and($paths->lock())->toBe($root.DIRECTORY_SEPARATOR.'.lock')
        ->and($paths->messagesDir())->toBe($root.DIRECTORY_SEPARATOR.'messages')
        ->and($paths->tmpDir())->toBe($root.DIRECTORY_SEPARATOR.'tmp')
        ->and($paths->tmp($messageId))->toBe($root.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.$messageId)
        ->and($paths->message($messageId))->toBe($root.DIRECTORY_SEPARATOR.'messages'.DIRECTORY_SEPARATOR.$messageId)
        ->and($paths->raw($messageId))->toBe($root.DIRECTORY_SEPARATOR.'messages'.DIRECTORY_SEPARATOR.$messageId.DIRECTORY_SEPARATOR.'raw.eml')
        ->and($paths->partsDir($messageId))->toBe($root.DIRECTORY_SEPARATOR.'messages'.DIRECTORY_SEPARATOR.$messageId.DIRECTORY_SEPARATOR.'parts')
        ->and($paths->part($messageId, $partId))->toBe($root.DIRECTORY_SEPARATOR.'messages'.DIRECTORY_SEPARATOR.$messageId.DIRECTORY_SEPARATOR.'parts'.DIRECTORY_SEPARATOR.$partId.'.bin');
});

it('rejects traversal in a message identifier', function () {
    $paths = new StoragePaths(sys_get_temp_dir().'/mailbox-paths');

    expect(fn () => $paths->message('../x'))
        ->toThrow(InvalidArgumentException::class, 'Invalid mailbox identifier.');
});

it('rejects traversal in a part identifier', function () {
    $paths = new StoragePaths(sys_get_temp_dir().'/mailbox-paths');

    expect(fn () => $paths->part('01ARZ3NDEKTSV4RRFFQ69G5FAV', '../x'))
        ->toThrow(InvalidArgumentException::class, 'Invalid mailbox identifier.');
});

it('rejects lowercase identifiers', function () {
    $paths = new StoragePaths(sys_get_temp_dir().'/mailbox-paths');

    expect(fn () => $paths->message('01arz3ndektsv4rrffq69g5fav'))
        ->toThrow(InvalidArgumentException::class, 'Invalid mailbox identifier.');
});
