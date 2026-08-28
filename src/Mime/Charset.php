<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

final class Charset
{
    public static function toUtf8(string $bytes, ?string $charset): string
    {
        $charset = trim((string) $charset);

        if ($charset !== '' && strcasecmp($charset, 'UTF-8') !== 0) {
            $supported = array_map('strtoupper', mb_list_encodings());

            if (in_array(strtoupper($charset), $supported, true)) {
                $converted = mb_convert_encoding($bytes, 'UTF-8', $charset);

                if (is_string($converted)) {
                    return $converted;
                }
            }
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    }
}
