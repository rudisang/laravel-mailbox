<?php

declare(strict_types=1);

use Rudisang\Mailbox\Mime\PartWriter;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\TextPart;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/mailbox-part-'.bin2hex(random_bytes(4)).'.bin';
});

afterEach(fn () => @unlink($this->path));

it('decodes base64 data parts back to the original bytes', function () {
    $binary = random_bytes(100_000);
    $part = new DataPart($binary, 'blob.bin', 'application/octet-stream');

    $result = PartWriter::write($part, $this->path);

    expect(file_get_contents($this->path))->toBe($binary)
        ->and($result['bytes'])->toBe(100_000)
        ->and($result['sha256'])->toBe(hash('sha256', $binary));
});

it('decodes quoted-printable text parts', function () {
    $text = 'Héllo wörld — a very long line '.str_repeat('with soft breaks ', 20)."\r\nsecond line";
    $part = new TextPart($text, 'utf-8', 'plain', 'quoted-printable');

    PartWriter::write($part, $this->path);

    expect(file_get_contents($this->path))->toBe($text);
});

it('flushes a quoted-printable decoder ending in a soft break', function () {
    $part = new class('unused', 'utf-8', 'plain', 'quoted-printable') extends TextPart
    {
        public function bodyToIterable(): iterable
        {
            yield 'tail=';
            yield "\r\n";
        }
    };

    $result = PartWriter::write($part, $this->path);

    expect(file_get_contents($this->path))->toBe('tail')
        ->and($result['bytes'])->toBe(4)
        ->and($result['sha256'])->toBe(hash('sha256', 'tail'));
});

it('decodes a bare-LF source body to the CRLF-canonical wire form', function () {
    $text = "first line\nsecond line";
    $part = new TextPart($text, 'utf-8', 'plain', 'quoted-printable');

    PartWriter::write($part, $this->path);

    // Symfony's QpEncoder canonicalises hard breaks.
    expect(file_get_contents($this->path))->toBe(str_replace("\n", "\r\n", $text));
});

it('passes 8bit text parts through unchanged', function () {
    $part = new TextPart("plain\r\ntext", 'utf-8', 'plain', '8bit');

    PartWriter::write($part, $this->path);

    expect(file_get_contents($this->path))->toBe("plain\r\ntext");
});

it('streams file-backed parts without loading them into memory', function () {
    $source = sys_get_temp_dir().'/mailbox-src-'.bin2hex(random_bytes(4)).'.bin';
    $fh = fopen($source, 'wb');
    for ($i = 0; $i < 1280; $i++) {
        fwrite($fh, random_bytes(8192)); // 10 MiB
    }
    fclose($fh);
    $part = new DataPart(new File($source), 'big.bin', 'application/octet-stream');
    $before = memory_get_peak_usage(true);

    $result = PartWriter::write($part, $this->path);

    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(12 * 1024 * 1024)
        ->and($result['bytes'])->toBe(1280 * 8192)
        ->and(hash_file('sha256', $this->path))->toBe(hash_file('sha256', $source));
    @unlink($source);
});
