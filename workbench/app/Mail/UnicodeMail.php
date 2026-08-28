<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UnicodeMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('noreply@acme.test', 'Acmé Señorita 日本'),
            subject: 'Ünïcödé subject — 日本語 テスト 🚀',
            bcc: [new Address('hidden@example.com', 'Hidden Bcc')],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Héllo wörld — 日本語 テスト 🚀 <strong>bold</strong></p>');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => "Ünïcödé content\n", 'résumé — 履歴書 🚀.txt')->withMime('text/plain'),
        ];
    }
}
