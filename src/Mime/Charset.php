<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

use ValueError;

/** @internal */
final class Charset
{
    public static function toUtf8(string $bytes, ?string $charset): string
    {
        $charset = trim((string) $charset);

        if ($charset === '' || strcasecmp($charset, 'UTF-8') === 0) {
            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        }

        try {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);

            if ($converted !== false) {
                return $converted;
            }
        } catch (ValueError) {
            // Unknown charset labels fall back to UTF-8 substitution below.
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    }
}
