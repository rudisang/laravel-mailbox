<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Rudisang\Mailbox\Testing\MailboxTester;
use Workbench\App\Mail\WelcomeMail;

uses(InteractsWithMailbox::class);

it('scopes each test to its own namespace and only sees its own captures', function () {
    config()->set('mailbox.namespace', 'someone-else');
    app(MessageStore::class); // same store
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('foreign')->text('x'));
    config()->set('mailbox.namespace', $this->mailbox()->namespace());
    Mail::to('me@example.com')->send(new WelcomeMail('Me'));

    expect($this->mailbox()->count())->toBe(1)
        ->and($this->mailbox()->anyNamespace()->count())->toBe(2)
        ->and($this->mailbox()->namespace())->toHaveLength(26);
});

it('selects the local mailer and restores configuration afterwards', function () {
    expect(config('mail.default'))->toBe('local')
        ->and(mailbox())->toBeInstanceOf(MailboxTester::class)
        ->and(mailbox())->toBe($this->mailbox());
    $this->tearDownInteractsWithMailbox();
    expect(config('mailbox.namespace'))->toBeNull();
    $this->setUpInteractsWithMailbox();
});

it('honours MAILBOX_NAMESPACE from the environment', function () {
    $this->tearDownInteractsWithMailbox();
    config()->set('mailbox.namespace', 'from-env');
    $this->setUpInteractsWithMailbox();

    expect($this->mailbox()->namespace())->toBe('from-env');
});

it('waits for captures with a bounded timeout', function () {
    $start = microtime(true);
    expect(fn () => $this->mailbox()->waitForCapture(1, 0.3))->toThrow(AssertionFailedError::class, 'Timed out');
    expect(microtime(true) - $start)->toBeLessThan(1.5);

    Mail::to('me@example.com')->send(new WelcomeMail('Me'));
    $new = $this->mailbox()->waitForCapture(1, 1.0);
    expect($new)->toHaveCount(1)->and($new[0]->subject())->toContain('Welcome');
});
