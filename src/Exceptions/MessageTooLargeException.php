<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

/** Thrown by the mailbox transport when a raw message exceeds the configured limit. */
final class MessageTooLargeException extends TransportException
{
    public static function limit(int $limit): self
    {
        return new self(sprintf('The message exceeds the mailbox raw size limit of %d bytes (config mailbox.limits.raw_bytes).', $limit));
    }
}
