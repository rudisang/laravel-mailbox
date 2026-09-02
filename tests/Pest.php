<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\EnvironmentGuard;
use Rudisang\Mailbox\Support\StoragePaths;
use Rudisang\Mailbox\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Concurrency');

function mailboxStore(): MessageStore
{
    return app(MessageStore::class);
}

function mailboxPaths(): StoragePaths
{
    return app(StoragePaths::class);
}

function mailboxGuard(): EnvironmentGuard
{
    return app(EnvironmentGuard::class);
}

function mailboxSendOne(string $subject): void
{
    Mail::mailer('local')->send([], [], fn ($message) => $message
        ->from('a@example.com')
        ->to('b@example.com')
        ->subject($subject)
        ->text('x'));
}

function mailboxCapture(string $subject = 'Route test'): string
{
    Mail::mailer('local')->send([], [], fn ($message) => $message
        ->from('a@example.com')
        ->to('b@example.com')
        ->subject($subject)
        ->html('<p>'.$subject.'</p>')
        ->text($subject));

    return mailboxStore()->list()[0]->id;
}
