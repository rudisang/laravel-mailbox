<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Exercises the preview sandbox and attachment policy with hostile content.
 */
class HostileMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: '<script>alert(1)</script> "Quoted" & hostile subject');
    }

    public function content(): Content
    {
        return new Content(view: 'workbench::mail.hostile');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>', 'image.svg')
                ->withMime('image/svg+xml'),
            Attachment::fromData(fn () => '<html><script>alert(3)</script></html>', "../../etc/passwd\r\nX-Injected: 1.html")
                ->withMime('text/html'),
            Attachment::fromData(fn () => 'MZ fake executable', 'CON.exe')
                ->withMime('application/x-msdownload'),
            Attachment::fromData(fn () => 'not really a png', 'fake.png')
                ->withMime('image/png'),
        ];
    }
}
