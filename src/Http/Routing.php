<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http;

final class Routing
{
    public static function basePath(): string
    {
        return url(trim((string) config('mailbox.path', '_mailbox'), '/'));
    }

    public static function ulid(): string
    {
        return '[0-9A-HJKMNP-TV-Z]{26}';
    }
}
