<?php

declare(strict_types=1);

use Rudisang\Mailbox\Support\Limits;

it('uses defaults when keys are missing', function () {
    $limits = Limits::fromConfig([]);

    expect($limits->rawBytes)->toBe(50 * 1024 * 1024)
        ->and($limits->parts)->toBe(100)
        ->and($limits->depth)->toBe(30)
        ->and($limits->headerBytes)->toBe(256 * 1024)
        ->and($limits->searchTextBytes)->toBe(512 * 1024)
        ->and($limits->previewBytes)->toBe(2 * 1024 * 1024);
});

it('clamps values into safe ranges', function () {
    $limits = Limits::fromConfig(['raw_bytes' => 1, 'parts' => 100000, 'depth' => 0, 'header_bytes' => -5]);

    expect($limits->rawBytes)->toBe(64 * 1024)
        ->and($limits->parts)->toBe(1000)
        ->and($limits->depth)->toBe(1)
        ->and($limits->headerBytes)->toBe(4 * 1024);
});
