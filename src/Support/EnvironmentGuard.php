<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Rudisang\Mailbox\Exceptions\MailboxDisabledException;

final class EnvironmentGuard
{
    public function __construct(private readonly Application $app, private readonly Repository $config) {}

    public function allows(): bool
    {
        return $this->reason() === null;
    }

    /** Returns null when allowed, otherwise a short machine-readable reason. */
    public function reason(): ?string
    {
        if ($this->config->get('mailbox.enabled') === false) {
            return 'disabled';
        }

        $environment = (string) $this->app->environment();
        $allowed = $this->config->get('mailbox.environments', ['local', 'testing']);
        $allowed = is_array($allowed) ? array_values(array_filter($allowed, 'is_string')) : ['local', 'testing'];

        if ($environment === 'production' || ! in_array($environment, $allowed, true)) {
            return 'environment:'.$environment;
        }

        return null;
    }

    public function assertAllowed(): void
    {
        $reason = $this->reason();

        if ($reason !== null) {
            throw MailboxDisabledException::because($reason);
        }
    }
}
