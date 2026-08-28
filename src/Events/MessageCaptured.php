<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Events;

/** Event dispatched after a message capture is stored successfully. */
final readonly class MessageCaptured
{
    public function __construct(
        public string $id,
        public int $seq,
        public ?string $namespace,
    ) {}
}
