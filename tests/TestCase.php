<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Tests;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Rudisang\Mailbox\MailboxServiceProvider;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected string $mailboxStoragePath;

    protected function setUp(): void
    {
        $this->mailboxStoragePath = sys_get_temp_dir().'/laravel-mailbox-tests/'.bin2hex(random_bytes(6));

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Break container<->service reference cycles before deleting: on Windows
        // the mailbox index SQLite handle must actually be closed before its
        // file can be unlinked, and the destroyed app's cycles keep the PDO
        // alive until the garbage collector runs.
        gc_collect_cycles();

        // Direct Filesystem, not the File facade: tearDown runs after the app is
        // destroyed, and older framework/testbench releases flush the container
        // aliases, so facade resolution throws there.
        (new Filesystem)->deleteDirectory($this->mailboxStoragePath);
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
        $app['config']->set('mailbox.retention.max_messages', 5000);
        $app['config']->set('mailbox.retention.max_bytes', 1024 * 1024 * 1024);
    }
}
