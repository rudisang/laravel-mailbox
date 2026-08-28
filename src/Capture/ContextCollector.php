<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job as JobContract;
use Rudisang\Mailbox\Mime\AddressNormalizer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class ContextCollector
{
    /** @var array{subject: ?string, to: list<string>, data: array<string, mixed>}|null */
    private ?array $sending = null;

    /** @var array<string, string|null> */
    private array $job = [];

    /** @var array<string, string> */
    private array $appContext = [];

    /** @var (callable(array<string, string|null>): array<string, string|null>)|null */
    private $redactor = null;

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {}

    public function namespace(): ?string
    {
        $namespace = $this->config->get('mailbox.namespace');

        return is_string($namespace) && $namespace !== '' ? substr($namespace, 0, 128) : null;
    }

    /** @param array<string, mixed> $data */
    public function rememberSending(Email $message, array $data): void
    {
        $this->sending = [
            'subject' => $message->getSubject(),
            'to' => AddressNormalizer::emails(array_values($message->getTo())),
            'data' => $data,
        ];
    }

    public function forgetSending(): void
    {
        $this->sending = null;
    }

    public function jobStarted(string $connection, JobContract $job): void
    {
        $payload = $job->payload();
        $this->job = [
            'job' => $job->resolveName(),
            'job_id' => $job->uuid() ?? (isset($payload['uuid']) ? (string) $payload['uuid'] : null),
            'queue' => $job->getQueue(),
            'connection' => $connection,
        ];
    }

    public function jobFinished(): void
    {
        $this->job = [];
    }

    /** @param array<string, mixed> $scalars */
    public function add(array $scalars): void
    {
        foreach ($scalars as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $this->appContext['app.'.substr((string) $key, 0, 64)] = substr((string) $value, 0, 1024);

            if (count($this->appContext) >= 64) {
                break;
            }
        }
    }

    public function redactUsing(?callable $fn): void
    {
        $this->redactor = $fn;
    }

    /** @return array<string, string|null> */
    public function take(RawMessage $original, ?string $mailer): array
    {
        $context = [
            'runtime' => $this->job !== [] ? 'queue' : ($this->app->runningInConsole() ? 'console' : 'http'),
            'mailer' => $mailer,
            'environment' => (string) $this->app->environment(),
            'locale' => (string) $this->app->getLocale(),
            'request_method' => null,
            'request_path' => null,
            'command' => null,
            'mailable' => null,
            'notification' => null,
            'notification_id' => null,
            'job' => null,
            'job_id' => null,
            'queue' => null,
            'connection' => null,
        ];

        if (! $this->app->runningInConsole() && $this->app->bound('request')) {
            $request = $this->app->make('request');
            $context['request_method'] = $request->getMethod();
            $context['request_path'] = '/'.ltrim($request->path(), '/');
        } elseif (isset($_SERVER['argv'][1]) && is_string($_SERVER['argv'][1])) {
            $context['command'] = substr($_SERVER['argv'][1], 0, 128);
        }

        if ($this->sending !== null && $original instanceof Email
            && $this->sending['subject'] === $original->getSubject()
            && $this->sending['to'] === AddressNormalizer::emails(array_values($original->getTo()))) {
            $data = $this->sending['data'];
            $context['mailable'] = isset($data['__laravel_mailable']) && is_string($data['__laravel_mailable']) ? $data['__laravel_mailable'] : null;
            $context['notification'] = isset($data['__laravel_notification']) && is_string($data['__laravel_notification']) ? $data['__laravel_notification'] : null;
            $context['notification_id'] = isset($data['__laravel_notification_id']) ? (string) $data['__laravel_notification_id'] : null;
        }

        $this->sending = null;

        $context = array_merge($context, $this->job, $this->appContext);
        $this->appContext = [];

        if ($this->redactor !== null) {
            $context = ($this->redactor)($context);
        }

        return $this->bound($context);
    }

    /**
     * @param  array<mixed, mixed>  $context
     * @return array<string, string|null>
     */
    private function bound(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if (count($out) >= 64) {
                break;
            }

            if ($value !== null && ! is_scalar($value)) {
                continue;
            }

            $out[substr((string) $key, 0, 64)] = $value === null ? null : substr((string) $value, 0, 1024);
        }

        return $out;
    }
}
