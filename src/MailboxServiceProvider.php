<?php

declare(strict_types=1);

namespace Rudisang\Mailbox;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\ServiceProvider;
use Rudisang\Mailbox\Capture\ContextCollector;
use Rudisang\Mailbox\Capture\FailureInjector;
use Rudisang\Mailbox\Capture\MessageRecorder;
use Rudisang\Mailbox\Mime\StructuredMessageExtractor;
use Rudisang\Mailbox\Storage\MaintenanceLock;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Pruner;
use Rudisang\Mailbox\Support\EnvironmentGuard;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Support\StoragePaths;
use Rudisang\Mailbox\Transport\LocalTransportFactory;

class MailboxServiceProvider extends ServiceProvider
{
    private mixed $previousNamespace = null;

    private bool $namespaceOverridden = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailbox.php', 'mailbox');

        /** @var Repository $config */
        $config = $this->app->make('config');

        if ($config->get('mail.mailers.local') === null) {
            $config->set('mail.mailers.local', ['transport' => 'local']);
        }

        $this->app->singleton(EnvironmentGuard::class, fn (Application $app) => new EnvironmentGuard($app, $app->make('config')));
        $this->app->singleton(Limits::class, fn (Application $app) => Limits::fromConfig((array) $app->make('config')->get('mailbox.limits', [])));
        $this->app->singleton(StructuredMessageExtractor::class, fn (Application $app) => new StructuredMessageExtractor($app->make(Limits::class)));
        $this->app->singleton(StoragePaths::class, fn (Application $app) => StoragePaths::fromConfig($app->make('config'), $app));
        $this->app->singleton(MaintenanceLock::class, fn (Application $app) => new MaintenanceLock($app->make(StoragePaths::class)->lock()));
        $this->app->singleton(MessageStore::class, fn (Application $app) => new MessageStore($app->make(StoragePaths::class), $app->make(MaintenanceLock::class)));
        $this->app->singleton(FailureInjector::class);
        $this->app->singleton(ContextCollector::class, fn (Application $app) => new ContextCollector($app, $app->make('config')));
        $this->app->singleton(Pruner::class, fn (Application $app) => new Pruner(
            $app->make(MessageStore::class),
            $app->make(MaintenanceLock::class),
            (array) $app->make('config')->get('mailbox.retention', []),
        ));
        $this->app->singleton(MessageRecorder::class, fn (Application $app) => new MessageRecorder(
            $app->make(StoragePaths::class),
            $app->make(MessageStore::class),
            $app->make(StructuredMessageExtractor::class),
            $app->make(ContextCollector::class),
            $app->make(Limits::class),
            $app->make(MaintenanceLock::class),
            $app->make(Pruner::class),
            $app->make('events'),
            $app->make(FailureInjector::class),
        ));
        $this->app->singleton(LocalTransportFactory::class, fn (Application $app) => new LocalTransportFactory($app));
    }

    public function boot(): void
    {
        /** @var MailManager $mailManager */
        $mailManager = $this->app->make('mail.manager');
        $mailManager->extend('local', function (array $config = []) {
            return $this->app->make(LocalTransportFactory::class)->make($config);
        });

        if ($this->app->make(EnvironmentGuard::class)->allows()) {
            $events = $this->app->make('events');
            $events->listen(MessageSending::class, fn (MessageSending $event) => $this->app->make(ContextCollector::class)->rememberSending($event->message, $event->data));
            $events->listen(MessageSent::class, fn () => $this->app->make(ContextCollector::class)->forgetSending());
            $events->listen(JobProcessing::class, function (JobProcessing $event): void {
                $collector = $this->app->make(ContextCollector::class);
                $collector->jobStarted((string) $event->connectionName, $event->job);
                $payload = $event->job->payload();

                if (isset($payload['mailbox_namespace']) && is_string($payload['mailbox_namespace'])) {
                    $config = $this->app->make('config');
                    $this->previousNamespace = $config->get('mailbox.namespace');
                    $config->set('mailbox.namespace', $payload['mailbox_namespace']);
                    $this->namespaceOverridden = true;
                }
            });

            foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
                $events->listen($event, function (): void {
                    $this->app->make(ContextCollector::class)->jobFinished();

                    if ($this->namespaceOverridden) {
                        $this->app->make('config')->set('mailbox.namespace', $this->previousNamespace);
                        $this->namespaceOverridden = false;
                    }
                });
            }

            Queue::createPayloadUsing(function () {
                $namespace = $this->app->make('config')->get('mailbox.namespace');

                return is_string($namespace) && $namespace !== '' ? ['mailbox_namespace' => $namespace] : [];
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mailbox.php' => $this->app->configPath('mailbox.php'),
            ], ['mailbox', 'mailbox-config']);

            AboutCommand::add('Mailbox', fn () => [
                'Enabled' => $this->app->make(EnvironmentGuard::class)->allows() ? 'Yes' : 'No ('.$this->app->make(EnvironmentGuard::class)->reason().')',
                'Path' => '/'.trim((string) $this->app->make('config')->get('mailbox.path', '_mailbox'), '/'),
                'Storage' => $this->app->make(StoragePaths::class)->root,
            ]);
        }
    }
}
