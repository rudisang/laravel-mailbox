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

it('honours an existing mailbox.namespace config value', function () {
    $this->tearDownInteractsWithMailbox();
    config()->set('mailbox.namespace', 'from-env');
    $this->setUpInteractsWithMailbox();

    expect($this->mailbox()->namespace())->toBe('from-env');
});

it('returns captures newest first and advances the wait high-water mark', function () {
    Mail::to('first@example.com')->send(new WelcomeMail('First'));
    Mail::to('second@example.com')->send(new WelcomeMail('Second'));

    $firstWait = $this->mailbox()->waitForCapture(2);

    expect($firstWait)->toHaveCount(2)
        ->and($firstWait[0]->to()[0]['address'])->toBe('second@example.com')
        ->and($firstWait[1]->to()[0]['address'])->toBe('first@example.com');

    Mail::to('third@example.com')->send(new WelcomeMail('Third'));

    $secondWait = $this->mailbox()->waitForCapture(1);

    expect($secondWait)->toHaveCount(1)
        ->and($secondWait[0]->to()[0]['address'])->toBe('third@example.com');
});

it('keeps find and clear scoped to the current namespace', function () {
    $tester = $this->mailbox();
    $namespace = $tester->namespace();
    config()->set('mailbox.namespace', 'someone-else');
    mailboxSendOne('foreign');
    $foreign = $tester->anyNamespace()->latest();
    config()->set('mailbox.namespace', $namespace);
    Mail::to('me@example.com')->send(new WelcomeMail('Me'));

    expect($tester->find($foreign->id()))->toBeNull();

    $tester->clear();

    expect($tester->count())->toBe(0)
        ->and($tester->anyNamespace()->find($foreign->id())?->id())->toBe($foreign->id());
});

it('does not satisfy a wait with a capture from another namespace', function () {
    $namespace = $this->mailbox()->namespace();
    config()->set('mailbox.namespace', 'someone-else');
    mailboxSendOne('foreign');
    config()->set('mailbox.namespace', $namespace);

    $start = microtime(true);
    expect(fn () => $this->mailbox()->waitForCapture(1, 0.3))
        ->toThrow(AssertionFailedError::class, 'Timed out');
    expect(microtime(true) - $start)->toBeLessThan(1.5);
});

it('throws when the mailbox helper has no trait-bound tester', function () {
    $this->app->forgetInstance(MailboxTester::class);

    expect(fn () => mailbox())->toThrow(LogicException::class, 'InteractsWithMailbox trait');
});

it('waits for captures with a bounded timeout', function () {
    $start = microtime(true);
    expect(fn () => $this->mailbox()->waitForCapture(1, 0.3))->toThrow(AssertionFailedError::class, 'Timed out');
    expect(microtime(true) - $start)->toBeLessThan(1.5);

    Mail::to('me@example.com')->send(new WelcomeMail('Me'));
    $new = $this->mailbox()->waitForCapture(1, 1.0);
    expect($new)->toHaveCount(1)->and($new[0]->subject())->toContain('Welcome');
});
