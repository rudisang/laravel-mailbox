<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoiceMail extends Mailable
{
    public function __construct(public int $number = 123, public string $amount = 'R 1,250.00') {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('billing@acme.test', 'Acme Billing'),
            replyTo: [new Address('support@acme.test', 'Acme Support')],
            cc: [new Address('accounts@example.com', 'Accounts')],
            bcc: [new Address('audit@example.com', 'Audit Trail')],
            subject: 'Your invoice #'.$this->number,
            tags: ['billing', 'invoice'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'workbench::mail.invoice', text: 'workbench::mail.invoice-text');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => "%PDF-1.4\n% Fake invoice PDF for the workbench\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n", 'invoice-'.$this->number.'.pdf')
                ->withMime('application/pdf'),
            Attachment::fromData(fn () => "date,item,amount\n2026-08-28,Subscription,1250.00\n", 'line-items.csv')
                ->withMime('text/csv'),
        ];
    }
}
