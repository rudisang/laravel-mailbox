<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Rudisang\Mailbox\Capture\ContextCollector;
use Rudisang\Mailbox\Mailbox;
use Rudisang\Mailbox\Storage\MessageStore;
use Symfony\Component\Mime\Email;
use Workbench\App\Mail\WelcomeMail;
use Workbench\App\Notifications\VerifyAccount;
use Workbench\App\Providers\WorkbenchServiceProvider;

beforeEach(function () {
    $this->app->register(WorkbenchServiceProvider::class);
});

it('records the mailable and notification class best-effort', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    Notification::route('mail', 'n@example.com')->notify(new VerifyAccount);
    $list = app(MessageStore::class)->list();

    expect($list[1]->context['mailable'])->toBe(WelcomeMail::class)
        ->and($list[0]->context['notification'])->toBe(VerifyAccount::class)
        ->and($list[0]->context['notification_id'])->not->toBeNull()
        ->and($list[1]->context['runtime'])->toBe('console')
        ->and($list[1]->context['environment'])->toBe('testing');
});

it('records queue job context and takes the namespace from the job payload', function () {
    config()->set('mailbox.namespace', 'outer');
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['uuid' => 'job-uuid-1', 'displayName' => 'App\\Jobs\\SendMail', 'mailbox_namespace' => 'from-payload']);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendMail');
    $job->shouldReceive('uuid')->andReturn('job-uuid-1');
    $job->shouldReceive('getQueue')->andReturn('emails');

    event(new JobProcessing('database', $job));
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('in job')->text('x'));
    event(new JobProcessed('database', $job));
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('after job')->text('x'));
    $list = app(MessageStore::class)->list();

    expect($list[1]->namespace)->toBe('from-payload')
        ->and($list[1]->context['job'])->toBe('App\\Jobs\\SendMail')
        ->and($list[1]->context['job_id'])->toBe('job-uuid-1')
        ->and($list[1]->context['queue'])->toBe('emails')
        ->and($list[1]->context['runtime'])->toBe('queue')
        ->and($list[0]->namespace)->toBe('outer')
        ->and($list[0]->context['job'])->toBeNull();
});

it('restores nested job namespace and context for the matching job', function () {
    config()->set('mailbox.namespace', 'configured-ns');
    $makeJob = static function (string $name, string $uuid, string $namespace): Job {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'uuid' => $uuid,
            'displayName' => $name,
            'mailbox_namespace' => $namespace,
        ]);
        $job->shouldReceive('resolveName')->andReturn($name);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('getQueue')->andReturn('emails');

        return $job;
    };
    $outer = $makeJob('App\\Jobs\\Outer', 'outer-job', 'outer-ns');
    $inner = $makeJob('App\\Jobs\\Inner', 'inner-job', 'inner-ns');

    event(new JobProcessing('sync', $outer));
    event(new JobProcessing('sync', $inner));
    mailboxSendOne('inner capture');
    event(new JobProcessed('sync', $inner));
    mailboxSendOne('outer capture');
    event(new JobProcessed('sync', $outer));
    mailboxSendOne('after jobs');

    $list = app(MessageStore::class)->list();
    expect($list[2]->namespace)->toBe('inner-ns')
        ->and($list[2]->context['job'])->toBe('App\\Jobs\\Inner')
        ->and($list[1]->namespace)->toBe('outer-ns')
        ->and($list[1]->context['job'])->toBe('App\\Jobs\\Outer')
        ->and($list[0]->namespace)->toBe('configured-ns')
        ->and($list[0]->context['job'])->toBeNull();
});

it('ignores duplicate and unknown job completion events', function () {
    config()->set('mailbox.namespace', 'configured-ns');
    $outer = Mockery::mock(Job::class);
    $outer->shouldReceive('payload')->andReturn(['uuid' => 'outer', 'mailbox_namespace' => 'outer-ns']);
    $outer->shouldReceive('resolveName')->andReturn('Outer');
    $outer->shouldReceive('uuid')->andReturn('outer');
    $outer->shouldReceive('getQueue')->andReturn('emails');
    $inner = Mockery::mock(Job::class);
    $inner->shouldReceive('payload')->andReturn(['uuid' => 'inner', 'mailbox_namespace' => 'inner-ns']);
    $inner->shouldReceive('resolveName')->andReturn('Inner');
    $inner->shouldReceive('uuid')->andReturn('inner');
    $inner->shouldReceive('getQueue')->andReturn('emails');
    $exception = new RuntimeException('failed');

    event(new JobProcessing('sync', $outer));
    event(new JobProcessing('sync', $inner));
    event(new JobExceptionOccurred('sync', $inner, $exception));
    event(new JobFailed('sync', $inner, $exception));
    mailboxSendOne('still outer');
    event(new JobProcessed('sync', $outer));

    $record = app(MessageStore::class)->list()[0];
    expect($record->namespace)->toBe('outer-ns')
        ->and($record->context['job'])->toBe('Outer');
});

it('adds the current namespace to queued job payloads', function () {
    config()->set('mailbox.namespace', 'ns-42');
    config()->set('queue.default', 'sync');
    $payload = null;
    Queue::before(function (JobProcessing $event) use (&$payload) {
        $payload = $event->job->payload();
    });

    Mail::to('q@example.com')->queue(new WelcomeMail('Queued'));

    expect($payload['mailbox_namespace'] ?? null)->toBe('ns-42');
});

it('accepts bounded app context and applies redaction', function () {
    Mailbox::redactContextUsing(fn (array $ctx) => array_merge($ctx, ['app.secret' => '[redacted]']));
    Mailbox::context(['secret' => 'hunter2', 'order' => 77, 'huge' => str_repeat('x', 5000), 'obj' => new stdClass]);

    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('ctx')->text('x'));
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('ctx2')->text('x'));
    $list = app(MessageStore::class)->list();

    expect($list[1]->context['app.secret'])->toBe('[redacted]')
        ->and($list[1]->context['app.order'])->toBe('77')
        ->and(strlen($list[1]->context['app.huge']))->toBe(1024)
        ->and(array_key_exists('app.obj', $list[1]->context))->toBeFalse()
        ->and(array_key_exists('app.order', $list[0]->context))->toBeFalse();
    Mailbox::redactContextUsing(null);
});

it('re-bounds context after redaction', function () {
    $extra = [];
    foreach (range(1, 70) as $index) {
        $extra['app.extra.'.$index] = 'value-'.$index;
    }

    Mailbox::redactContextUsing(fn (array $ctx) => array_merge($ctx, [
        'app.big' => str_repeat('x', 5000),
        'app.obj' => new stdClass,
        str_repeat('k', 100) => 'v',
    ], $extra));

    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('re-bound')->text('x'));
    $context = app(MessageStore::class)->list()[0]->context;

    expect(strlen($context['app.big']))->toBe(1024)
        ->and(array_key_exists('app.obj', $context))->toBeFalse()
        ->and($context[str_repeat('k', 64)])->toBe('v')
        ->and(count($context))->toBeLessThanOrEqual(64);
    Mailbox::redactContextUsing(null);
});

it('does not misattribute a mailable when subject and recipients differ', function () {
    $stale = (new Email)->subject('stale')->to('x@example.com');
    app(ContextCollector::class)->rememberSending($stale, ['__laravel_mailable' => 'App\\Mail\\Stale']);

    app('events')->forget(MessageSending::class);
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('y@example.com')->subject('fresh')->text('x'));
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('z@example.com')->subject('later')->text('x'));
    $list = app(MessageStore::class)->list();

    expect($list[1]->context['mailable'])->toBeNull()
        ->and($list[0]->context['mailable'])->toBeNull();
});

it('attributes a mailable when subject and recipients match', function () {
    $matching = (new Email)->subject('matching')->to('x@example.com');
    app(ContextCollector::class)->rememberSending($matching, ['__laravel_mailable' => 'App\\Mail\\Matching']);

    app('events')->forget(MessageSending::class);
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('x@example.com')->subject('matching')->text('x'));

    expect(app(MessageStore::class)->list()[0]->context['mailable'])->toBe('App\\Mail\\Matching');
});
