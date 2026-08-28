<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

/** Thrown by the mailbox transport when capture is disabled by the environment guard. */
final class MailboxDisabledException extends TransportException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('The "local" mailbox transport is disabled (%s). It only runs in the environments listed in config("mailbox.environments").', $reason));
    }
}
