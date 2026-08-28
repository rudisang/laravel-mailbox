<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Support;

use RuntimeException;

/** @internal */
final class Assets
{
    public static function path(string $file): string
    {
        if (! in_array($file, ['mailbox.css', 'mailbox.js'], true)) {
            throw new RuntimeException('Unknown mailbox asset.');
        }

        return dirname(__DIR__, 2).'/resources/dist/'.$file;
    }

    public static function version(string $file): string
    {
        $version = hash_file('sha256', self::path($file));

        if ($version === false) {
            throw new RuntimeException('Unable to version mailbox asset.');
        }

        return substr($version, 0, 12);
    }

    public static function url(string $file): string
    {
        return route('mailbox.asset', ['file' => $file]).'?v='.self::version($file);
    }
}
