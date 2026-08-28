<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

use RuntimeException;

final class FailureInjector
{
    /** @var array<string, true> */
    private array $stages = [];

    public function failAt(string $stage): void
    {
        $this->stages[$stage] = true;
    }

    public function reset(): void
    {
        $this->stages = [];
    }

    public function check(string $stage): void
    {
        if (isset($this->stages[$stage])) {
            unset($this->stages[$stage]);

            throw new RuntimeException('Injected failure at '.$stage);
        }
    }
}
