<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewsletterMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'August product update: dark mode, faster search, and more');
    }

    public function content(): Content
    {
        return new Content(view: 'workbench::mail.newsletter');
    }
}
