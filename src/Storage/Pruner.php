<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/** @internal */
final class Pruner
{
    private readonly MessageStore $store;

    private readonly MaintenanceLock $lock;

    /** @var array<string, mixed> */
    private readonly array $retention;

    /** @param array<string, mixed> $retention */
    public function __construct(
        MessageStore $store,
        MaintenanceLock $lock,
        array $retention,
    ) {
        $this->store = $store;
        $this->lock = $lock;
        $this->retention = $retention;
    }

    public function prune(bool $blocking = false): int
    {
        // Schema initialization takes the same lock, so complete it before maintenance.
        $this->store->pdo();

        $result = $this->lock->exclusive(function (): int {
            $removed = 0;
            $days = max(0, (int) ($this->retention['days'] ?? 7));

            if ($days > 0) {
                $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->sub(new DateInterval('P'.$days.'D'))
                    ->format('Y-m-d\TH:i:s\Z');

                foreach ($this->store->idsOlderThan($cutoff) as $id) {
                    $this->store->delete($id);
                    $removed++;
                }
            }

            $maxMessages = max(1, (int) ($this->retention['max_messages'] ?? 1000));

            foreach ($this->store->idsBeyondCount($maxMessages) as $id) {
                $this->store->delete($id);
                $removed++;
            }

            $maxBytes = max(1024 * 1024, (int) ($this->retention['max_bytes'] ?? 250 * 1024 * 1024));

            foreach ($this->store->idsBeyondBytes($maxBytes) as $id) {
                $this->store->delete($id);
                $removed++;
            }

            return $removed;
        }, $blocking);

        return is_int($result) ? $result : 0;
    }
}
