<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

use Throwable;

final class RawHeaderBlock
{
    /** @return list<array{0: string, 1: string}> */
    public static function read(string $rawPath, int $maxBytes): array
    {
        return self::readWithMeta($rawPath, $maxBytes)['headers'];
    }

    /** @return array{headers: list<array{0: string, 1: string}>, truncated: bool} */
    public static function readWithMeta(string $rawPath, int $maxBytes): array
    {
        $handle = @fopen($rawPath, 'rb');

        if ($handle === false) {
            return ['headers' => [], 'truncated' => false];
        }

        $headers = [];
        $current = null;
        $consumed = 0;
        $complete = false;
        $truncated = false;

        try {
            while (true) {
                $remaining = $maxBytes - $consumed;

                if ($remaining <= 0) {
                    break;
                }

                $line = fgets($handle, $remaining + 1);

                if ($line === false) {
                    break;
                }

                $length = strlen($line);
                $consumed += $length;
                $endsWithNewline = str_ends_with($line, "\n");

                if (! $endsWithNewline && $length === $remaining) {
                    self::append($headers, $current, rtrim($line, "\r\n"));

                    break;
                }

                $isFinalLine = ! $endsWithNewline && $length < $remaining;

                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    $complete = true;

                    break;
                }

                if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                    $current[1] .= ' '.trim($line);

                    if ($isFinalLine) {
                        break;
                    }

                    continue;
                }

                self::flush($headers, $current);
                $colon = strpos($line, ':');

                if ($colon === false || $colon === 0) {
                    $current = null;

                    if ($isFinalLine) {
                        break;
                    }

                    continue;
                }

                $current = [trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1))];

                if ($isFinalLine) {
                    break;
                }
            }

            self::flush($headers, $current);

            if (! $complete && $consumed >= $maxBytes) {
                $truncated = fgetc($handle) !== false;
            }
        } finally {
            fclose($handle);
        }

        return ['headers' => $headers, 'truncated' => $truncated];
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

        try {
            $decoded = self::decodeMimeHeader($value);
        } catch (Throwable) {
            return $value;
        }

        return is_string($decoded) ? $decoded : $value;
    }

    private static function decodeMimeHeader(string $value): mixed
    {
        return mb_decode_mimeheader($value);
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
