<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PlainTextMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your one-time code');
    }

    public function content(): Content
    {
        return new Content(text: 'workbench::mail.code-text');
    }
}
