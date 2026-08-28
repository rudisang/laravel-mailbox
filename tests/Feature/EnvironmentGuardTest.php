<?php

declare(strict_types=1);

use Rudisang\Mailbox\Exceptions\MailboxDisabledException;

it('allows testing and local by default', function () {
    expect(mailboxGuard()->allows())->toBeTrue();

    $this->app['env'] = 'local';
    expect(mailboxGuard()->allows())->toBeTrue();
});

it('refuses production even when listed', function () {
    $this->app['env'] = 'production';
    config()->set('mailbox.environments', ['local', 'testing', 'production']);

    expect(mailboxGuard()->allows())->toBeFalse()
        ->and(mailboxGuard()->reason())->toBe('environment:production');
});

it('refuses production case-insensitively even when listed with the same casing', function () {
    $this->app['env'] = 'Production';
    config()->set('mailbox.environments', ['Production']);

    expect(mailboxGuard()->allows())->toBeFalse()
        ->and(mailboxGuard()->reason())->toBe('environment:Production');
});

it('matches allowed environments case-insensitively', function () {
    $this->app['env'] = 'LOCAL';
    config()->set('mailbox.environments', ['local']);

    expect(mailboxGuard()->allows())->toBeTrue();
});

it('refuses environments that are not listed', function () {
    $this->app['env'] = 'staging';

    expect(mailboxGuard()->allows())->toBeFalse()->and(mailboxGuard()->reason())->toBe('environment:staging');
});

it('can only be disabled, never force-enabled, by the enabled flag', function () {
    config()->set('mailbox.enabled', false);
    expect(mailboxGuard()->allows())->toBeFalse()->and(mailboxGuard()->reason())->toBe('disabled');

    config()->set('mailbox.enabled', true);
    $this->app['env'] = 'staging';
    expect(mailboxGuard()->allows())->toBeFalse();
});

it('throws a transport exception when asserting outside allowed environments', function () {
    $this->app['env'] = 'production';

    mailboxGuard()->assertAllowed();
})->throws(MailboxDisabledException::class);
