<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

use Rudisang\Mailbox\Exceptions\MessageTooLargeException;
use RuntimeException;
use Throwable;

final class RawStreamWriter
{
    /**
     * @param  iterable<string>  $chunks
     * @return array{bytes: int, sha256: string}
     */
    public static function write(iterable $chunks, string $path, int $maxBytes): array
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the raw message file for writing.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $closed = false;

        try {
            foreach ($chunks as $chunk) {
                $chunk = (string) $chunk;
                $length = strlen($chunk);
                $bytes += $length;

                if ($bytes > $maxBytes) {
                    throw MessageTooLargeException::limit($maxBytes);
                }

                hash_update($hash, $chunk);
                $offset = 0;

                while ($offset < $length) {
                    $written = fwrite($handle, substr($chunk, $offset));

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to write the raw message file.');
                    }

                    $offset += $written;
                }
            }

            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush the raw message file.');
            }

            if (! fsync($handle)) {
                throw new RuntimeException('Unable to sync the raw message file.');
            }

            $closed = true;

            if (! fclose($handle)) {
                throw new RuntimeException('Unable to close the raw message file.');
            }

            clearstatcache(true, $path);
            $size = filesize($path);

            if ($size === false || $size !== $bytes) {
                throw new RuntimeException('The raw message file size does not match the written byte count.');
            }
        } catch (Throwable $e) {
            if (! $closed) {
                @fclose($handle);
            }

            @unlink($path);

            throw $e;
        }

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }
}
