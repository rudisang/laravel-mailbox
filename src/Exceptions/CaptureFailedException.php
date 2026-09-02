<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

/** Thrown by the mailbox transport when a message capture cannot be stored. */
final class CaptureFailedException extends TransportException
{
    public static function wrap(Throwable $exception): self
    {
        return new self('The mailbox could not store the message: '.$exception->getMessage(), 0, $exception);
    }
}
