<?php

declare(strict_types=1);

it('keeps the compressed mailbox assets within the release budget', function () {
    $dist = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'dist';
    $css = file_get_contents($dist.DIRECTORY_SEPARATOR.'mailbox.css');
    $js = file_get_contents($dist.DIRECTORY_SEPARATOR.'mailbox.js');

    if ($css === false || $js === false) {
        throw new RuntimeException('Unable to read the mailbox distribution assets.');
    }

    $compressedCss = gzencode($css);
    $compressedJs = gzencode($js);

    if ($compressedCss === false || $compressedJs === false) {
        throw new RuntimeException('Unable to compress the mailbox distribution assets.');
    }

    expect(strlen($compressedCss) + strlen($compressedJs))->toBeLessThanOrEqual(250 * 1024);
});
