<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

final class RawHeaderBlock
{
    /** @return list<array{0: string, 1: string}> */
    public static function read(string $rawPath, int $maxBytes): array
    {
        $handle = @fopen($rawPath, 'rb');

        if ($handle === false) {
            return [];
        }

        $headers = [];
        $current = null;
        $consumed = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $consumed += strlen($line);

                if ($consumed > $maxBytes) {
                    $line = substr($line, 0, max(0, strlen($line) - ($consumed - $maxBytes)));
                    $line = rtrim($line, "\r\n");
                    self::append($headers, $current, $line);

                    break;
                }

                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    break;
                }

                if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                    $current[1] .= ' '.trim($line);

                    continue;
                }

                self::flush($headers, $current);
                $colon = strpos($line, ':');

                if ($colon === false || $colon === 0) {
                    $current = null;

                    continue;
                }

                $current = [trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1))];
            }

            self::flush($headers, $current);
        } finally {
            fclose($handle);
        }

        return $headers;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $headers
     * @param  array{0: string, 1: string}|null  $current
     *
     * @param-out list<array{0: string, 1: string}> $headers
     * @param-out null $current
     */
    private static function flush(array &$headers, ?array &$current): void
    {
        if ($current !== null) {
            $headers[] = [$current[0], self::decode($current[1])];
            $current = null;
        }
    }

    /**
     * Handles the truncated final line.
     *
     * @param  list<array{0: string, 1: string}>  $headers
     * @param  array{0: string, 1: string}|null  $current
     *
     * @param-out list<array{0: string, 1: string}> $headers
     * @param-out null $current
     */
    private static function append(array &$headers, ?array &$current, string $line): void
    {
        $pending = $current;

        if ($pending !== null && ($line === '' || $line[0] === ' ' || $line[0] === "\t")) {
            $pending[1] .= ' '.trim($line);
        } elseif (($colon = strpos($line, ':')) !== false && $colon > 0) {
            self::flush($headers, $pending);
            $pending = [trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1))];
        }

        self::flush($headers, $pending);
        $current = null;
    }

    private static function decode(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return is_string($decoded) ? $decoded : $value;
    }

    /** @param  list<array{0: string, 1: string}>  $headers */
    public static function has(array $headers, string $name): bool
    {
        return self::first($headers, $name) !== null;
    }

    /** @param  list<array{0: string, 1: string}>  $headers */
    public static function first(array $headers, string $name): ?string
    {
        foreach ($headers as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $headers
     * @return list<string>
     */
    public static function all(array $headers, string $name): array
    {
        $values = [];
        foreach ($headers as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
