<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Rudisang\Mailbox\Capture\FailureInjector;
use Rudisang\Mailbox\Capture\MessageRecorder;
use Rudisang\Mailbox\Events\MessageCaptured;
use Rudisang\Mailbox\Exceptions\MailboxDisabledException;
use Rudisang\Mailbox\Exceptions\MessageTooLargeException;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Support\StoragePaths;
use Rudisang\Mailbox\Tests\Fixtures\Emails;
use Rudisang\Mailbox\Transport\LocalTransportFactory;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Workbench\App\Mail\WelcomeMail;
use Workbench\App\Notifications\VerifyAccount;
use Workbench\App\Providers\WorkbenchServiceProvider;

function store(): MessageStore
{
    return app(MessageStore::class);
}

function paths(): StoragePaths
{
    return app(StoragePaths::class);
}

it('captures the exact prepared stream, envelope and original recipients', function () {
    $sent = Mail::mailer('local')->send([], [], function ($message) {
        $message->from('sender@example.com')->to('to@example.com')->cc('cc@example.com')->bcc('hidden@example.com')->subject('Capture me')->html('<p>Hi</p>')->text('Hi');
    });
    $record = store()->list()[0];
    $raw = file_get_contents(paths()->raw($record->id));

    expect($raw)->toBe($sent->getSymfonySentMessage()->toString())
        ->and($record->messageId)->toBe($sent->getSymfonySentMessage()->getMessageId())
        ->and($record->rawSha256)->toBe(hash('sha256', $raw))
        ->and($record->rawBytes)->toBe(strlen($raw))
        ->and($raw)->not->toContain('hidden@example.com')
        ->and($record->bcc[0]['address'])->toBe('hidden@example.com')
        ->and($record->envelopeRecipients)->toBe(['to@example.com', 'cc@example.com', 'hidden@example.com'])
        ->and($record->envelopeSender)->toBe('sender@example.com')
        ->and($record->rawHeaders)->toContain(['Subject', 'Capture me'])
        ->and(array_column($record->rawHeaders, 0))->not->toContain('Bcc')
        ->and($record->mailer)->toBe('local')
        ->and($record->hasHtml)->toBeTrue()->and($record->hasText)->toBeTrue()
        ->and(store()->parts($record->id))->toHaveCount(3);
});

it('captures a mailable, a notification and a queued mailable through the sync queue', function () {
    $this->app->register(WorkbenchServiceProvider::class);

    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    Notification::route('mail', 'n@example.com')->notify(new VerifyAccount);
    Mail::to('q@example.com')->queue(new WelcomeMail('Queued'));

    expect(store()->count())->toBe(3)
        ->and(store()->list(['q' => 'Verify your email']))->toHaveCount(1)
        ->and(store()->list(['attachments' => false]))->toHaveCount(3);
});

it('emits MessageCaptured only after commit and never fails the send because of listeners', function () {
    $events = Event::getFacadeRoot();
    Event::fake([MessageCaptured::class]);
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('Event')->text('x'));
    Event::assertDispatched(MessageCaptured::class, fn ($e) => $e->seq === 1 && strlen($e->id) === 26);

    Event::swap($events);
    app(FailureInjector::class)->failAt('in_event');
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('Event 2')->text('x'));
    expect(store()->count())->toBe(2);
});

it('throws a transport exception and leaves nothing visible when storage fails', function () {
    foreach (['before_raw_write', 'after_raw_write', 'after_extract', 'after_rename', 'before_commit'] as $stage) {
        app(FailureInjector::class)->reset();
        app(FailureInjector::class)->failAt($stage);

        expect(fn () => Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject($stage)->text('x')))
            ->toThrow(TransportException::class);
        expect(store()->count())->toBe(0, "stage {$stage} left a visible row");
        expect(glob(paths()->messagesDir().'/*') ?: [])->toBe([], "stage {$stage} left a message directory");
    }
});

it('rejects oversize messages with a transport exception', function () {
    config()->set('mailbox.limits.raw_bytes', 64 * 1024);
    $this->app->forgetInstance(Limits::class);
    $this->app->forgetInstance(MessageRecorder::class);
    Mail::purge('local');

    expect(fn () => Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('big')->text(str_repeat('x', 200 * 1024))))
        ->toThrow(MessageTooLargeException::class);
    expect(store()->count())->toBe(0);
});

it('refuses to capture outside allowed environments', function () {
    $this->app['env'] = 'production';
    Mail::purge('local');

    expect(fn () => Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('prod')->text('x')))
        ->toThrow(MailboxDisabledException::class);
    expect(glob(paths()->messagesDir().'/*') ?: [])->toBe([]);
});

it('stores arbitrary raw messages as unsupported', function () {
    $raw = new RawMessage("From: a@example.com\r\nTo: b@example.com\r\nBcc: c@example.com\r\nSubject: raw\r\n\r\nbody");
    $transport = app(LocalTransportFactory::class)->make(['transport' => 'local']);

    $transport->send($raw, new Envelope(new Address('a@example.com'), [new Address('b@example.com')]));
    $record = store()->list()[0];

    expect($record->parseStatus)->toBe('unsupported')
        ->and($record->rawHeaders)->toContain(['Bcc', 'c@example.com'])
        ->and(file_get_contents(paths()->raw($record->id)))->toContain('Subject: raw');
});

it('does not deduplicate identical messages', function () {
    $email = Emails::plain();
    $transport = app(LocalTransportFactory::class)->make(['transport' => 'local']);
    $transport->send($email);
    $transport->send($email);

    expect(store()->count())->toBe(2);
});
