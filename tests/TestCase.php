<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Rudisang\Mailbox\MailboxServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $mailboxStoragePath;

    protected function setUp(): void
    {
        $this->mailboxStoragePath = sys_get_temp_dir().'/laravel-mailbox-tests/'.bin2hex(random_bytes(6));

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        File::deleteDirectory($this->mailboxStoragePath);
    }

    protected function getPackageProviders($app): array
    {
        return [
            MailboxServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('mail.default', 'local');
        $app['config']->set('mailbox.storage_path', $this->mailboxStoragePath);
    }
}
