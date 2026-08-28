<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Console;

use Illuminate\Console\Command;
use Rudisang\Mailbox\Storage\MaintenanceLock;
use Rudisang\Mailbox\Storage\MessageStore;

final class ClearCommand extends Command
{
    /** @var string */
    protected $signature = 'mailbox:clear {--force : Clear the mailbox without confirmation}';

    /** @var string */
    protected $description = 'Clear every captured mailbox message';

    public function __construct(
        private readonly MessageStore $store,
        private readonly MaintenanceLock $lock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) $this->option('force') && $this->input->isInteractive() && ! $this->confirm('Clear every captured mailbox message?')) {
            $this->components->info('Mailbox was not cleared.');

            return self::SUCCESS;
        }

        // Schema initialization takes the same lock, so complete it before maintenance.
        $this->store->pdo();

        $this->lock->exclusive(function (): void {
            $this->store->clear();
        }, true);

        $this->components->info('Mailbox cleared.');

        return self::SUCCESS;
    }
}
