<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Support;

final class Limits
{
    public function __construct(
        public readonly int $rawBytes,
        public readonly int $parts,
        public readonly int $depth,
        public readonly int $headerBytes,
        public readonly int $searchTextBytes,
        public readonly int $previewBytes,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            self::clamp($config['raw_bytes'] ?? null, 50 * 1024 * 1024, 64 * 1024, 1024 * 1024 * 1024),
            self::clamp($config['parts'] ?? null, 100, 1, 1000),
            self::clamp($config['depth'] ?? null, 30, 1, 100),
            self::clamp($config['header_bytes'] ?? null, 256 * 1024, 4 * 1024, 4 * 1024 * 1024),
            self::clamp($config['search_text_bytes'] ?? null, 512 * 1024, 1024, 8 * 1024 * 1024),
            self::clamp($config['preview_bytes'] ?? null, 2 * 1024 * 1024, 16 * 1024, 32 * 1024 * 1024),
        );
    }

    private static function clamp(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
