<?php

declare(strict_types=1);

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
    $this->artisan('vendor:publish', ['--tag' => 'mailbox-config'])->assertSuccessful();

    expect(config_path('mailbox.php'))->toBeFile();
    @unlink(config_path('mailbox.php'));
});
