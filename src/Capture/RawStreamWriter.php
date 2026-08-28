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

        try {
            foreach ($chunks as $chunk) {
                $chunk = (string) $chunk;
                $bytes += strlen($chunk);

                if ($bytes > $maxBytes) {
                    throw MessageTooLargeException::limit($maxBytes);
                }

                hash_update($hash, $chunk);

                if (fwrite($handle, $chunk) === false) {
                    throw new RuntimeException('Unable to write the raw message file.');
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

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }
}
