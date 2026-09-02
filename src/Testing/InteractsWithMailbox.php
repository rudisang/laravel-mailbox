<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Testing;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Rudisang\Mailbox\Capture\MessageRecorder;
use Rudisang\Mailbox\Security\AttachmentPolicy;
use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\StoragePaths;

/**
 * Public Laravel test-case integration for the mailbox testing API.
 *
 * @phpstan-require-extends TestCase
 */
// @phpstan-ignore trait.unused (Public consumer trait; usages live in downstream Laravel test cases.)
trait InteractsWithMailbox
{
    /** @var array<string, mixed> */
    private array $mailboxConfigSnapshot = [];

    protected function setUpInteractsWithMailbox(): void
    {
        $config = $this->app['config'];
        $this->mailboxConfigSnapshot = [
            'mail.default' => $config->get('mail.default'),
            'mailbox.namespace' => $config->get('mailbox.namespace'),
        ];

        $namespace = $config->get('mailbox.namespace');
        $namespace = is_string($namespace) && $namespace !== '' ? $namespace : (string) Str::ulid();
        $namespace = substr($namespace, 0, 128);
        $config->set('mailbox.namespace', $namespace);
        $config->set('mail.default', 'local');
        $this->app->make('mail.manager')->purge('local');
        $this->app->forgetInstance(MessageRecorder::class);

        $store = $this->app->make(MessageStore::class);
        $tester = new MailboxTester(
            $store,
            $this->app->make(StoragePaths::class),
            $this->app->make(HtmlPreviewSanitizer::class),
            $this->app->make(AttachmentPolicy::class),
            $namespace,
            $store->status($namespace)['seq'],
        );
        $this->app->instance(MailboxTester::class, $tester);
    }

    protected function tearDownInteractsWithMailbox(): void
    {
        foreach ($this->mailboxConfigSnapshot as $key => $value) {
            $this->app['config']->set($key, $value);
        }

        $this->app->make('mail.manager')->purge('local');
        $this->app->forgetInstance(MailboxTester::class);
    }

    public function mailbox(): MailboxTester
    {
        return $this->app->make(MailboxTester::class);
    }
}
