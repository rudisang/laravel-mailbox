<?php

declare(strict_types=1);

use Rudisang\Mailbox\Exceptions\MailboxDisabledException;
use Rudisang\Mailbox\Support\EnvironmentGuard;

function guard(): EnvironmentGuard
{
    return app(EnvironmentGuard::class);
}

it('allows testing and local by default', function () {
    expect(guard()->allows())->toBeTrue();

    $this->app['env'] = 'local';
    expect(guard()->allows())->toBeTrue();
});

it('refuses production even when listed', function () {
    $this->app['env'] = 'production';
    config()->set('mailbox.environments', ['local', 'testing', 'production']);

    expect(guard()->allows())->toBeFalse()
        ->and(guard()->reason())->toBe('environment:production');
});

it('refuses environments that are not listed', function () {
    $this->app['env'] = 'staging';

    expect(guard()->allows())->toBeFalse()->and(guard()->reason())->toBe('environment:staging');
});

it('can only be disabled, never force-enabled, by the enabled flag', function () {
    config()->set('mailbox.enabled', false);
    expect(guard()->allows())->toBeFalse()->and(guard()->reason())->toBe('disabled');

    config()->set('mailbox.enabled', true);
    $this->app['env'] = 'staging';
    expect(guard()->allows())->toBeFalse();
});

it('throws a transport exception when asserting outside allowed environments', function () {
    $this->app['env'] = 'production';

    guard()->assertAllowed();
})->throws(MailboxDisabledException::class);
