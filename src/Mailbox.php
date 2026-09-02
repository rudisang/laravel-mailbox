<?php

declare(strict_types=1);

namespace Rudisang\Mailbox;

use Rudisang\Mailbox\Capture\ContextCollector;

/** Public facade for capture context and context redaction rules. */
final class Mailbox
{
    /** @param array<string, mixed> $scalars */
    public static function context(array $scalars): void
    {
        app(ContextCollector::class)->add($scalars);
    }

    public static function redactContextUsing(?callable $fn): void
    {
        app(ContextCollector::class)->redactUsing($fn);
    }
}
