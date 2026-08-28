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
    /**
     * @var array{
     *     subject: ?string,
     *     to: list<string>,
     *     mailable: ?string,
     *     notification: ?string,
     *     notification_id: ?string
     * }|null
     */
    private ?array $sending = null;

    /** @var array<int, array<string, string|null>> */
    private array $jobs = [];

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
            'mailable' => is_scalar($data['__laravel_mailable'] ?? null)
                ? (string) $data['__laravel_mailable']
                : null,
            'notification' => is_scalar($data['__laravel_notification'] ?? null)
                ? (string) $data['__laravel_notification']
                : null,
            'notification_id' => is_scalar($data['__laravel_notification_id'] ?? null)
                ? (string) $data['__laravel_notification_id']
                : null,
        ];
    }

    public function forgetSending(): void
    {
        $this->sending = null;
    }

    public function jobStarted(string $connection, JobContract $job): void
    {
        $payload = $job->payload();
        $payloadUuid = $payload['uuid'] ?? null;
        $this->jobs[spl_object_id($job)] = [
            'job' => $job->resolveName(),
            'job_id' => $job->uuid() ?? (is_scalar($payloadUuid) ? (string) $payloadUuid : null),
            'queue' => $job->getQueue(),
            'connection' => $connection,
        ];
    }

    public function jobFinishedFor(JobContract $job): void
    {
        unset($this->jobs[spl_object_id($job)]);
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
        $job = $this->currentJob();
        $context = [
            'runtime' => $job !== [] ? 'queue' : ($this->app->runningInConsole() ? 'console' : 'http'),
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
            $context['mailable'] = $this->sending['mailable'];
            $context['notification'] = $this->sending['notification'];
            $context['notification_id'] = $this->sending['notification_id'];
        }

        $this->sending = null;

        $context = array_merge($context, $job, $this->appContext);
        $this->appContext = [];

        if ($this->redactor !== null) {
            $context = ($this->redactor)($context);
        }

        return $this->bound($context);
    }

    /** @return array<string, string|null> */
    private function currentJob(): array
    {
        $id = array_key_last($this->jobs);

        return $id === null ? [] : $this->jobs[$id];
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
