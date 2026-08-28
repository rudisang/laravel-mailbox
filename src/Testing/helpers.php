<?php

declare(strict_types=1);
use Illuminate\Container\Container;
use Rudisang\Mailbox\Testing\MailboxTester;

if (! function_exists('mailbox')) {
    function mailbox(): MailboxTester
    {
        $app = Container::getInstance();

        if (! $app->bound(MailboxTester::class)) {
            throw new LogicException('mailbox() requires the Rudisang\Mailbox\Testing\InteractsWithMailbox trait on the test case.');
        }

        return $app->make(MailboxTester::class);
    }
}
