<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends Mailable
{
    public function __construct(public string $name = 'Ada')
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Acme, '.$this->name,
            tags: ['onboarding'],
            metadata: ['user_id' => '42'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'workbench::mail.welcome',
            text: 'workbench::mail.welcome-text',
        );
    }
}
