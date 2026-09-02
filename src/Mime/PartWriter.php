<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

use RuntimeException;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\MessagePart;
use Throwable;

/** @internal */
final class PartWriter
{
    /** @return array{bytes: int, sha256: string} */
    public static function write(AbstractPart $part, string $path): array
    {
        $headerBody = $part->getPreparedHeaders()->getHeaderBody('Content-Transfer-Encoding');
        $encoding = is_string($headerBody) && ! $part instanceof MessagePart ? strtolower(trim($headerBody)) : '';

        $filter = match ($encoding) {
            'base64' => 'convert.base64-decode',
            'quoted-printable' => 'convert.quoted-printable-decode',
            default => null,
        };

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the part file for writing.');
        }

        $filterResource = null;
        $closed = false;

        try {
            if ($filter !== null) {
                $filterResource = stream_filter_append($handle, $filter, STREAM_FILTER_WRITE);

                if ($filterResource === false) {
                    throw new RuntimeException('Unable to attach the decoding stream filter.');
                }
            }

            foreach ($part->bodyToIterable() as $chunk) {
                $chunk = (string) $chunk;

                if ($filter === 'convert.base64-decode') {
                    $chunk = str_replace(["\r", "\n"], '', $chunk);
                }

                $length = strlen($chunk);
                $offset = 0;

                while ($offset < $length) {
                    $written = fwrite($handle, substr($chunk, $offset));

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to write the part file.');
                    }

                    $offset += $written;
                }
            }

            if ($filterResource !== null && ! stream_filter_remove($filterResource)) {
                throw new RuntimeException('Unable to remove the decoding stream filter.');
            }

            $filterResource = null;

            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush the part file.');
            }

            if (! fsync($handle)) {
                throw new RuntimeException('Unable to sync the part file.');
            }

            $closed = true;

            if (! fclose($handle)) {
                throw new RuntimeException('Unable to close the part file.');
            }
        } catch (Throwable $e) {
            if (is_resource($filterResource)) {
                @stream_filter_remove($filterResource);
            }

            if (! $closed) {
                @fclose($handle);
            }

            @unlink($path);

            throw $e;
        }

        clearstatcache(true, $path);
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);

        if ($bytes === false || $sha256 === false) {
            throw new RuntimeException('Unable to read back the part file.');
        }

        return ['bytes' => $bytes, 'sha256' => $sha256];
    }
}
