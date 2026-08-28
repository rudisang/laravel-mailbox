<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Mime\RawMessage;

final class ContextCollector
{
    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {}

    public function namespace(): ?string
    {
        $namespace = $this->config->get('mailbox.namespace');

        return is_string($namespace) && $namespace !== '' ? substr($namespace, 0, 128) : null;
    }

    /** @return array<string, string|null> */
    public function take(RawMessage $original, ?string $mailer): array
    {
        return [
            'runtime' => $this->app->runningInConsole() ? 'console' : 'http',
            'mailer' => $mailer,
            'environment' => (string) $this->app->environment(),
            'locale' => (string) $this->app->getLocale(),
        ];
    }
}
