<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Events;

final readonly class MessageCaptured
{
    public function __construct(
        public string $id,
        public int $seq,
        public ?string $namespace,
    ) {}
}
