<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use Rudisang\Mailbox\Support\StoragePaths;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\TextPart;
use Workbench\App\Mail\HostileMail;
use Workbench\App\Mail\InvoiceMail;
use Workbench\App\Mail\WelcomeMail;

uses(InteractsWithMailbox::class);

it('asserts on the final invoice email', function () {
    Mail::to('buyer@example.com')->send(new InvoiceMail(123));

    $this->mailbox()->latest()
        ->assertFrom('billing@acme.test')
        ->assertTo('buyer@example.com')
        ->assertCc('accounts@example.com')
        ->assertBcc('audit@example.com')
        ->assertReplyTo('support@acme.test')
        ->assertSubject('Your invoice #123')
        ->assertSubjectContains('invoice')
        ->assertHtmlContains('Invoice #123')
        ->assertSeeInHtml('Amount due')
        ->assertTextContains('Amount due')
        ->assertHasAttachment('invoice-123.pdf', 'application/pdf')
        ->assertHasAttachment('line-items.csv')
        ->assertAttachmentCount(2)
        ->assertHeader('Subject', 'Your invoice #123')
        ->assertHeader('X-Tag', 'billing')
        ->assertRawHeaderMissing('Bcc')
        ->assertRawContains('Content-Type: multipart/mixed')
        ->assertEnvelopeSender('billing@acme.test')
        ->assertEnvelopeContains('audit@example.com')
        ->assertTag('billing')
        ->assertNoParseErrors()
        ->assertNoRemoteImages()
        ->assertNoScripts();
});

it('exposes facts and inline images', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    $m = mailbox()->latest();

    $m->assertHasInlineImage(array_values(array_filter($m->parts(), fn ($p) => $p->isInline))[0]->contentId)
        ->assertMetadata('user_id', '42');
    expect($m->html())->toContain('Welcome aboard')
        ->and($m->text())->toContain('Welcome aboard')
        ->and($m->raw())->toContain('Subject: Welcome')
        ->and($m->messageId())->not->toBeNull()
        ->and($m->context()['mailable'])->toBe(WelcomeMail::class)
        ->and($m->links()[0]['url'])->toContain('acme.test/onboarding')
        ->and($m->attachmentContent('missing.pdf'))->toBeNull();
});

it('presents captured legacy charset text as utf-8', function () {
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Legacy charset')
        ->setBody(new TextPart("caf\xe9", 'iso-8859-1', 'plain', '8bit'));

    Mail::mailer('local')->getSymfonyTransport()->send($email);
    $message = mailbox()->latest();

    expect($message->record()->previewText)->toBe('café')
        ->and($message->text())->toBe('café');
});

it('handles stale raw and attachment files without warnings', function () {
    Mail::to('buyer@example.com')->send(new InvoiceMail(123));
    $message = mailbox()->latest();
    $attachment = $message->attachments()[0];
    $paths = app(StoragePaths::class);

    unlink($paths->raw($message->id()));
    unlink($paths->part($message->id(), $attachment->id));

    expect($message->raw())->toBe('')
        ->and($message->attachmentContent((string) $attachment->filename))->toBeNull();
});

it('fails with fact-source labelled messages', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));

    expect(fn () => mailbox()->latest()->assertTo('nobody@example.com'))->toThrow(AssertionFailedError::class, 'original message');
    expect(fn () => mailbox()->latest()->assertHeader('X-Nope'))->toThrow(AssertionFailedError::class, 'raw header');
    expect(fn () => mailbox()->latest()->assertEnvelopeContains('nobody@example.com'))->toThrow(AssertionFailedError::class, 'envelope');
    expect(fn () => mailbox()->latest()->assertHtmlContains('nope'))->toThrow(AssertionFailedError::class);
});

it('flags hostile mail through diagnostics assertions', function () {
    Mail::to('v@example.com')->send(new HostileMail);

    expect(fn () => mailbox()->latest()->assertNoScripts())->toThrow(AssertionFailedError::class);
    expect(fn () => mailbox()->latest()->assertNoRemoteImages())->toThrow(AssertionFailedError::class);
    $diagnostics = mailbox()->latest()->diagnostics();
    expect($diagnostics['results'])->not->toBe([])
        ->and(array_column($diagnostics['results'], 'rule'))
        ->toContain('html.event_handlers')
        ->toContain('html.javascript_urls');
});

it('queries by recipient, subject, tag and message id', function () {
    Mail::to('a@example.com')->send(new InvoiceMail(1));
    Mail::to('b@example.com')->send(new WelcomeMail('B'));
    $tester = mailbox();

    expect($tester->count())->toBe(2)
        ->and($tester->whereTo('a@example.com'))->toHaveCount(1)
        ->and($tester->whereSubject('your invoice #1'))->toHaveCount(1)
        ->and($tester->whereTag('onboarding'))->toHaveCount(1)
        ->and($tester->whereMessageId($tester->latest()->messageId()))->toHaveCount(1)
        ->and($tester->find($tester->latest()->id())?->id())->toBe($tester->latest()->id())
        ->and($tester->assertCaptured(2))->toBe($tester);
    $tester->clear();
    $tester->assertNothingCaptured();
});
