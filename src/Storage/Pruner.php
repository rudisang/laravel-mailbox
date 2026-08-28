<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

final class Pruner
{
    /** @param array<string, mixed> $retention */
    public function __construct(
        MessageStore $store,
        MaintenanceLock $lock,
        array $retention,
    ) {
        unset($store, $lock, $retention);
    }

    public function prune(bool $blocking = false): int
    {
        return 0;
    }
}
