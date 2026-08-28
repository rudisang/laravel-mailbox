<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

use RuntimeException;

class MaintenanceLock
{
    public function __construct(private readonly string $path) {}

    public function shared(callable $fn): mixed
    {
        return $this->run(LOCK_SH, $fn);
    }

    public function exclusive(callable $fn, bool $blocking = true): mixed
    {
        return $this->run($blocking ? LOCK_EX : LOCK_EX | LOCK_NB, $fn);
    }

    /** @param int<0, 7> $operation */
    private function run(int $operation, callable $fn): mixed
    {
        $handle = @fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the mailbox lock file.');
        }

        try {
            if (! flock($handle, $operation)) {
                return null;
            }

            try {
                return $fn();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
