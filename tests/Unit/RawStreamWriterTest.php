<?php

declare(strict_types=1);

use Rudisang\Mailbox\Capture\RawStreamWriter;
use Rudisang\Mailbox\Exceptions\MessageTooLargeException;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/mailbox-raw-'.bin2hex(random_bytes(4)).'.eml';
});

afterEach(function () {
    if (file_exists($this->path)) {
        unlink($this->path);
    }
});

it('streams chunks to disk and reports size and hash', function () {
    $result = RawStreamWriter::write(['Subject: hi', "\r\n", "\r\n", 'body'], $this->path, 1024);

    expect(file_get_contents($this->path))->toBe("Subject: hi\r\n\r\nbody")
        ->and($result['bytes'])->toBe(19)
        ->and($result['sha256'])->toBe(hash('sha256', "Subject: hi\r\n\r\nbody"));
});

it('aborts and removes the file when the limit is exceeded', function () {
    $generator = (function () {
        for ($i = 0; $i < 100; $i++) {
            yield str_repeat('x', 100);
        }
    })();

    expect(fn () => RawStreamWriter::write($generator, $this->path, 500))
        ->toThrow(MessageTooLargeException::class)
        ->and(file_exists($this->path))->toBeFalse();
});

it('does not buffer the whole message in memory', function () {
    $generator = (function () {
        for ($i = 0; $i < 2000; $i++) {
            yield str_repeat('y', 8192); // 16 MiB total
        }
    })();
    $before = memory_get_peak_usage(true);

    RawStreamWriter::write($generator, $this->path, 64 * 1024 * 1024);

    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(8 * 1024 * 1024)
        ->and(filesize($this->path))->toBe(2000 * 8192);
});
