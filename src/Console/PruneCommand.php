<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Console;

use Illuminate\Console\Command;
use Rudisang\Mailbox\Storage\Pruner;

/** @internal */
final class PruneCommand extends Command
{
    /** @var string */
    protected $signature = 'mailbox:prune';

    /** @var string */
    protected $description = 'Prune mailbox messages according to the retention policy';

    public function __construct(private readonly Pruner $pruner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $removed = $this->pruner->prune(true);
        $this->components->twoColumnDetail('Pruned', $removed.' messages');

        return self::SUCCESS;
    }
}
