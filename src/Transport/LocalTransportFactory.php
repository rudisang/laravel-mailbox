<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Transport;

use Illuminate\Contracts\Foundation\Application;
use Rudisang\Mailbox\Capture\MessageRecorder;
use Rudisang\Mailbox\Support\EnvironmentGuard;

final class LocalTransportFactory
{
    public function __construct(private readonly Application $app) {}

    /** @param array<string, mixed> $config */
    public function make(array $config): LocalTransport
    {
        $configuredName = $config['name'] ?? null;
        $mailer = is_string($configuredName) && $configuredName !== '' ? $configuredName : null;
        $configuredTransport = $config;
        unset($configuredTransport['name']);

        if ($mailer === null) {
            foreach ((array) $this->app->make('config')->get('mail.mailers', []) as $name => $candidate) {
                if ($candidate === $config || $candidate === $configuredTransport) {
                    $mailer = (string) $name;

                    break;
                }
            }
        }

        return new LocalTransport(
            $this->app->make(MessageRecorder::class),
            $this->app->make(EnvironmentGuard::class),
            $mailer,
        );
    }
}
