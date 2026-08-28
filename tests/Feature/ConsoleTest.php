<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\StoragePaths;

it('doctor reports healthy state and exits 0', function () {
    $this->artisan('mailbox:doctor', ['--json' => true])
        ->expectsOutputToContain('"label":"Schema","detail":"Version 1."')
        ->assertExitCode(0);
});

it('doctor reports a mailer collision, failover composition and production as critical', function () {
    config()->set('mail.mailers.local', ['transport' => 'smtp']);
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['smtp', 'local']]);

    $this->artisan('mailbox:doctor')->expectsOutputToContain('mail.mailers.local')->expectsOutputToContain('failover')->assertExitCode(1);

    $this->app['env'] = 'production';
    $this->artisan('mailbox:doctor', ['--json' => true])->assertExitCode(1);
});

it('doctor reports an unreadable schema version as critical', function () {
    app(MessageStore::class)->pdo()->exec("UPDATE mailbox_meta SET value = 'invalid' WHERE key = 'schema_version'");

    $this->artisan('mailbox:doctor', ['--json' => true])
        ->expectsOutputToContain('"label":"Schema","detail":"Unable to read mailbox schema version: Mailbox schema version could not be read."')
        ->assertExitCode(1);
});

it('doctor --repair removes orphans', function () {
    $paths = app(StoragePaths::class);
    $orphan = $paths->messagesDir().DIRECTORY_SEPARATOR.'01ORPHAN00000000000000000B';
    mkdir($orphan, 0755, true);

    $this->artisan('mailbox:doctor', ['--repair' => true])->expectsOutputToContain('orphan')->assertExitCode(0);
    expect(is_dir($orphan))->toBeFalse();
});

it('clear and prune commands work', function () {
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('x')->text('x'));

    $this->artisan('mailbox:prune')->assertExitCode(0);
    $this->artisan('mailbox:clear', ['--force' => true])->assertExitCode(0);
    expect(app(MessageStore::class)->count())->toBe(0);
});
