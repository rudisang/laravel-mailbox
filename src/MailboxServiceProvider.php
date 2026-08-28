<?php

declare(strict_types=1);

namespace Rudisang\Mailbox;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Rudisang\Mailbox\Support\EnvironmentGuard;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Support\StoragePaths;

class MailboxServiceProvider extends ServiceProvider
{
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
        $this->app->singleton(StoragePaths::class, fn (Application $app) => StoragePaths::fromConfig($app->make('config'), $app));
    }

    public function boot(): void
    {
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
