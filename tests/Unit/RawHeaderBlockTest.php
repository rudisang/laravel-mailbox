<?php

declare(strict_types=1);

use Rudisang\Mailbox\Capture\RawHeaderBlock;

function rawFile(string $content): string
{
    $path = sys_get_temp_dir().'/mailbox-hdr-'.bin2hex(random_bytes(4)).'.eml';
    file_put_contents($path, $content);

    return $path;
}

it('reads ordered headers, unfolds continuations and decodes encoded words', function () {
    $path = rawFile("From: a@example.com\r\nSubject: =?UTF-8?B?w5xuw69jw7Zkw6k=?=\r\nX-Long: first\r\n\tsecond\r\nX-Dup: 1\r\nX-Dup: 2\r\n\r\nbody\r\nSubject: not a header\r\n");

    $headers = RawHeaderBlock::read($path, 4096);

    expect($headers)->toBe([
        ['From', 'a@example.com'],
        ['Subject', 'Ünïcödé'],
        ['X-Long', 'first second'],
        ['X-Dup', '1'],
        ['X-Dup', '2'],
    ])->and(RawHeaderBlock::has($headers, 'x-dup'))->toBeTrue()
        ->and(RawHeaderBlock::first($headers, 'subject'))->toBe('Ünïcödé')
        ->and(RawHeaderBlock::all($headers, 'X-DUP'))->toBe(['1', '2'])
        ->and(RawHeaderBlock::has($headers, 'Bcc'))->toBeFalse();
    @unlink($path);
});

it('stops at the byte limit and never reads the body', function () {
    $path = rawFile('X-A: '.str_repeat('a', 100)."\r\nX-B: b\r\n\r\nbody");

    expect(RawHeaderBlock::read($path, 50))->toBe([['X-A', str_repeat('a', 45)]]);
    @unlink($path);
});

it('tolerates LF-only line endings and malformed lines', function () {
    $path = rawFile("Subject: ok\nno-colon-line\n: empty name\nX-Z: z\n\nbody");

    expect(RawHeaderBlock::read($path, 4096))->toBe([['Subject', 'ok'], ['X-Z', 'z']]);
    @unlink($path);
});
