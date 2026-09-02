<?php

declare(strict_types=1);

// Usage: php worker.php <storage-root> <count> <namespace> [body-bytes] [ready]
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Orchestra\Testbench\Foundation\Application;
use Rudisang\Mailbox\MailboxServiceProvider;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Pruner;
use Rudisang\Mailbox\Storage\Repair;

$app = Application::create(null, null, [
    'extra' => ['providers' => [MailboxServiceProvider::class]],
    'env' => ['APP_ENV' => 'testing'],
]);
$app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
$app['config']->set('mailbox.storage_path', $argv[1]);
$app['config']->set('mailbox.namespace', $argv[3]);
$app['config']->set('mail.default', 'local');

$mailer = $app->make('mail.manager')->mailer('local');
$pruner = $app->make(Pruner::class);
$repair = $app->make(Repair::class);
$count = (int) $argv[2];
$bodyBytes = isset($argv[4]) ? max(1, (int) $argv[4]) : 2000;

$app->make(MessageStore::class)->pdo();

if (($argv[5] ?? null) === 'ready') {
    echo "ready\n";
    fflush(STDOUT);
}

for ($i = 0; $i < $count; $i++) {
    $mailer->send([], [], fn ($m) => $m->from('w@example.com')->to('t@example.com')->subject("w {$argv[3]} {$i}")->text(str_repeat('x', $bodyBytes)));

    if ($i % 10 === 0) {
        $pruner->prune(false);
        $repair->repair();
    }
}

echo 'done';
