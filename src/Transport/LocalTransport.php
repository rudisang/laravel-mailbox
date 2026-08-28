<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Transport;

use Rudisang\Mailbox\Capture\MessageRecorder;
use Rudisang\Mailbox\Support\EnvironmentGuard;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** @internal */
final class LocalTransport extends AbstractTransport
{
    public function __construct(
        private readonly MessageRecorder $recorder,
        private readonly EnvironmentGuard $guard,
        private readonly ?string $mailer = null,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->guard->assertAllowed();
        $this->recorder->record($message, $this->mailer);
    }

    public function __toString(): string
    {
        return 'local';
    }
}
