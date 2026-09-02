<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;
use Workbench\App\Mail\HostileMail;
use Workbench\App\Mail\WelcomeMail;

it('serves a sandboxed, csp-protected html preview with cid images resolved to part urls', function () {
    Mail::to('v@example.com')->send(new WelcomeMail('Ada'));
    $id = app(MessageStore::class)->list()[0]->id;

    $response = $this->get('/_mailbox/messages/'.$id.'/preview/html');

    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $csp = $response->headers->get('Content-Security-Policy');
    $partsPrefix = rtrim((string) config('app.url'), '/').'/_mailbox/messages/'.$id.'/parts/';
    expect($csp)
        ->toContain("default-src 'none'")
        ->toContain('img-src data: '.$partsPrefix)
        ->not->toContain("img-src 'self'")
        ->toContain('sandbox')
        ->toContain("frame-ancestors 'self'");
    expect($response->getContent())->toContain('src="'.$partsPrefix)->not->toContain('href=');
});

it('neuters hostile mail and lists its links and diagnostics in the workbench', function () {
    Mail::to('v@example.com')->send(new HostileMail);
    $id = app(MessageStore::class)->list()[0]->id;

    $preview = $this->get('/_mailbox/messages/'.$id.'/preview/html');
    expect(strtolower($preview->getContent()))
        ->not->toContain('<script')
        ->not->toContain('onload')
        ->not->toContain('src="https://evil.example.com')
        ->not->toContain('href="https://evil.example.com');

    $detail = $this->get('/_mailbox/messages/'.$id.'?partial=detail');
    $detail->assertOk()->assertSee('reset-password?token=SECRET123')->assertSee('html.scripts_removed')->assertDontSee('<script>alert', false);
    $text = $this->get('/_mailbox/messages/'.$id.'/preview/text');
    $text->assertOk()->assertHeader('Content-Security-Policy');
    expect($text->headers->get('Cache-Control'))->toContain('no-store');
});

it('escapes hostile subjects in the workbench dom', function () {
    Mail::to('v@example.com')->send(new HostileMail);

    $this->get('/_mailbox')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});
