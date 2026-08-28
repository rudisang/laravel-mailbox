<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Workbench\App\Mail\WelcomeMail;

uses(InteractsWithMailbox::class);

it('attributes a queued mailable processed by a real worker to the queuing test', function () {
    $disabledFunctions = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));

    if (! function_exists('proc_open') || in_array('proc_open', $disabledFunctions, true)) {
        $this->markTestSkipped('proc_open is disabled; a real queue worker subprocess cannot be started.');
    }

    $database = $this->mailboxStoragePath.'/queue.sqlite';
    touch($database);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', $database);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'sqlite');
    DB::purge('sqlite');

    Schema::connection('sqlite')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Mail::to('queued@example.com')->queue(new WelcomeMail('Queued'));

    $packageRoot = dirname(__DIR__, 3);
    $command = [
        PHP_BINARY,
        'vendor/bin/testbench',
        'queue:work',
        'database',
        '--once',
        '--stop-when-empty',
    ];
    $environment = array_merge($_ENV, [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'local',
        'MAILBOX_STORAGE_PATH' => $this->mailboxStoragePath,
    ]);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $packageRoot, $environment, ['bypass_shell' => true]);

    expect($process)->toBeResource();

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, trim($stdout."\n".$stderr));

    $captures = mailbox()->waitForCapture(1, 10.0);
    $capture = $captures[0];

    expect($capture->record()->namespace)->toBe(mailbox()->namespace())
        ->and($capture->context()['job'])->not->toBeNull();
});
