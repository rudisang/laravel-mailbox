<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Rudisang\Mailbox\MailboxServiceProvider;
use Rudisang\Mailbox\Support\EnvironmentGuard;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Support\StoragePaths;

it('merges default configuration', function () {
    expect(config('mailbox.path'))->toBe('_mailbox')
        ->and(config('mailbox.environments'))->toBe(['local', 'testing'])
        ->and(config('mailbox.limits.parts'))->toBe(100);
});

it('adds the local mailer when the application has not defined one', function () {
    expect(config('mail.mailers.local'))->toBe(['transport' => 'local']);
});

it('never overwrites an application-defined local mailer', function () {
    $this->app['config']->set('mail.mailers.local', ['transport' => 'smtp', 'host' => 'example']);
    (new MailboxServiceProvider($this->app))->register();

    expect(config('mail.mailers.local.transport'))->toBe('smtp');
});

it('binds the support singletons', function () {
    expect($this->app->make(EnvironmentGuard::class))->toBe($this->app->make(EnvironmentGuard::class))
        ->and($this->app->make(Limits::class)->parts)->toBe(100)
        ->and($this->app->make(StoragePaths::class)->root)->toBe($this->mailboxStoragePath);
});

it('defaults storage to storage/framework/mailbox', function () {
    $this->app['config']->set('mailbox.storage_path', null);
    $this->app->forgetInstance(StoragePaths::class);

    expect($this->app->make(StoragePaths::class)->root)->toBe(storage_path('framework/mailbox'));
});

it('publishes the config with the mailbox-config tag', function () {
    // Registration is asserted directly against the service provider's
    // publish groups rather than actually running `vendor:publish`, which
    // would write into the shared Testbench skeleton
    // (vendor/orchestra/testbench-core/laravel/config) and can race with
    // sibling processes booting an app under `--parallel`.
    $configPath = realpath(__DIR__.'/../../config/mailbox.php');

    $normalize = fn (array $paths): array => collect($paths)
        ->mapWithKeys(fn ($destination, $source) => [realpath($source) => $destination])
        ->all();

    expect($normalize(ServiceProvider::pathsToPublish(MailboxServiceProvider::class, 'mailbox-config')))
        ->toBe([$configPath => config_path('mailbox.php')])
        ->and($normalize(ServiceProvider::pathsToPublish(MailboxServiceProvider::class, 'mailbox')))
        ->toBe([$configPath => config_path('mailbox.php')]);
});
