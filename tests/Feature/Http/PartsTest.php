<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;
use Workbench\App\Mail\HostileMail;
use Workbench\App\Mail\InvoiceMail;
use Workbench\App\Mail\WelcomeMail;

it('downloads attachments as octet-stream with safe dispositions', function () {
    Mail::to('b@example.com')->send(new InvoiceMail(9));
    $store = app(MessageStore::class);
    $id = $store->list()[0]->id;
    $pdf = array_values(array_filter($store->parts($id), fn ($p) => $p->filename === 'invoice-9.pdf'))[0];

    $response = $this->get('/_mailbox/messages/'.$id.'/parts/'.$pdf->id);

    $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=invoice-9.pdf')
        ->and($response->headers->get('Content-Length'))->toBe((string) $pdf->decodedBytes);
});

it('never inlines svg, html or mislabelled images and strips header injection from filenames', function () {
    Mail::to('b@example.com')->send(new HostileMail);
    $store = app(MessageStore::class);
    $id = $store->list()[0]->id;

    foreach ($store->parts($id) as $part) {
        if (! $part->isAttachment) {
            continue;
        }
        $response = $this->get('/_mailbox/messages/'.$id.'/parts/'.$part->id);
        $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->not->toContain("\n")->not->toContain('X-Injected:');
    }
});

it('inlines real inline images and 404s for foreign or unknown parts', function () {
    Mail::to('b@example.com')->send(new WelcomeMail('Ada'));
    Mail::to('b@example.com')->send(new InvoiceMail(1));
    $store = app(MessageStore::class);
    [$invoice, $welcome] = $store->list();
    $logo = array_values(array_filter($store->parts($welcome->id), fn ($p) => $p->isInline))[0];

    $this->get('/_mailbox/messages/'.$welcome->id.'/parts/'.$logo->id)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/_mailbox/messages/'.$invoice->id.'/parts/'.$logo->id)->assertNotFound();
    $this->get('/_mailbox/messages/'.$welcome->id.'/parts/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
});

it('serves raw source inline and as a download', function () {
    Mail::to('b@example.com')->send(new InvoiceMail(2));
    $id = app(MessageStore::class)->list()[0]->id;

    $inline = $this->get('/_mailbox/messages/'.$id.'/raw');
    $inline->assertOk()->assertHeader('Content-Type', 'text/plain; charset=us-ascii');
    expect($inline->streamedContent())->toContain('Subject: Your invoice #2');

    $download = $this->get('/_mailbox/messages/'.$id.'/raw?download=1');
    expect($download->headers->get('Content-Disposition'))->toBe('attachment; filename='.$id.'.eml')
        ->and($download->streamedContent())->toContain('Subject: Your invoice #2');
});
