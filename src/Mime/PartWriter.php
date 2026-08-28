<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

use RuntimeException;
use Symfony\Component\Mime\Part\AbstractPart;
use Throwable;

final class PartWriter
{
    /** @return array{bytes: int, sha256: string} */
    public static function write(AbstractPart $part, string $path): array
    {
        $headerBody = $part->getPreparedHeaders()->getHeaderBody('Content-Transfer-Encoding');
        $encoding = is_string($headerBody) ? strtolower(trim($headerBody)) : '';

        $filter = match ($encoding) {
            'base64' => 'convert.base64-decode',
            'quoted-printable' => 'convert.quoted-printable-decode',
            default => null,
        };

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the part file for writing.');
        }

        try {
            if ($filter !== null && stream_filter_append($handle, $filter, STREAM_FILTER_WRITE) === false) {
                throw new RuntimeException('Unable to attach the decoding stream filter.');
            }

            foreach ($part->bodyToIterable() as $chunk) {
                $chunk = (string) $chunk;

                if ($filter === 'convert.base64-decode') {
                    $chunk = str_replace(["\r", "\n"], '', $chunk);
                }

                if ($chunk !== '' && fwrite($handle, $chunk) === false) {
                    throw new RuntimeException('Unable to write the part file.');
                }
            }

            fflush($handle);
            fsync($handle);
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        }

        fclose($handle);

        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);

        if ($bytes === false || $sha256 === false) {
            throw new RuntimeException('Unable to read back the part file.');
        }

        return ['bytes' => $bytes, 'sha256' => $sha256];
    }
}
