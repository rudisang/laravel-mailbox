<?php

declare(strict_types=1);

namespace Rudisang\Mailbox;

use Illuminate\Support\ServiceProvider;

class MailboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailbox.php', 'mailbox');
    }

    public function boot(): void {}
}
