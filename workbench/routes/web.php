<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Workbench\App\Mail\HostileMail;
use Workbench\App\Mail\InvoiceMail;
use Workbench\App\Mail\NewsletterMail;
use Workbench\App\Mail\PlainTextMail;
use Workbench\App\Mail\UnicodeMail;
use Workbench\App\Mail\WelcomeMail;
use Workbench\App\Notifications\VerifyAccount;

$scenarios = [
    'welcome' => fn () => Mail::to('ada@example.com', 'Ada Lovelace')->send(new WelcomeMail('Ada')),
    'invoice' => fn () => Mail::to('buyer@example.com', 'Grace Hopper')->send(new InvoiceMail(123)),
    'newsletter' => fn () => Mail::to('subscriber@example.com')->send(new NewsletterMail),
    'hostile' => fn () => Mail::to('victim@example.com')->send(new HostileMail),
    'unicode' => fn () => Mail::to('ünïcode@example.com', 'Ünïcödé Üser')->send(new UnicodeMail),
    'plain' => fn () => Mail::to('code@example.com')->send(new PlainTextMail),
    'notification' => fn () => Notification::route('mail', 'new-user@example.com')->notify(new VerifyAccount),
    'queued' => fn () => Mail::to('queued@example.com')->queue(new WelcomeMail('Queued')),
];

Route::get('/', fn () => redirect('/_mailbox'));

Route::get('/demo', function () use ($scenarios) {
    $links = collect(array_keys($scenarios))
        ->map(fn ($key) => '<li><a href="/demo/send/'.$key.'">'.$key.'</a></li>')
        ->implode('');

    return '<h1>Mailbox workbench</h1><ul>'.$links.'<li><a href="/demo/send-all">send all</a></li></ul><p><a href="/_mailbox">Open Mailbox</a></p>';
});

Route::get('/demo/send/{scenario}', function (string $scenario) use ($scenarios) {
    abort_unless(isset($scenarios[$scenario]), 404);

    $scenarios[$scenario]();

    return redirect('/_mailbox');
});

Route::get('/demo/send-all', function () use ($scenarios) {
    foreach ($scenarios as $send) {
        $send();
    }

    return redirect('/_mailbox');
});
