<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\EnvironmentGuard;
use Rudisang\Mailbox\Support\StoragePaths;
use Throwable;

/** @internal */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'mailbox:doctor {--repair : Repair storage inconsistencies} {--json : Output findings as JSON}';

    /** @var string */
    protected $description = 'Inspect mailbox configuration and storage health';

    public function __construct(
        private readonly EnvironmentGuard $guard,
        private readonly StoragePaths $paths,
        private readonly MessageStore $store,
        private readonly Repair $repair,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $repairCounts = null;
        $repairError = null;

        if ((bool) $this->option('repair')) {
            try {
                $repairCounts = $this->repair->repair();
            } catch (Throwable $exception) {
                $repairError = $exception->getMessage();
            }
        }

        $findings = $this->findings();

        if ($repairError !== null) {
            $findings[] = $this->finding('critical', 'Repair', 'repair failed: '.$repairError);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'findings' => $findings,
                'repair' => $repairCounts,
            ], JSON_THROW_ON_ERROR));
        } else {
            if ($repairCounts !== null) {
                $this->components->twoColumnDetail('Repair', sprintf(
                    'Removed %d orphan directories, %d dangling rows, and %d stale tmp entries.',
                    $repairCounts['orphan_dirs'],
                    $repairCounts['dangling_rows'],
                    $repairCounts['stale_tmp'],
                ));
            }

            $this->table(
                ['Level', 'Finding', 'Detail'],
                array_map(
                    static fn (array $finding): array => [$finding['level'], $finding['label'], $finding['detail']],
                    $findings,
                ),
            );
        }

        foreach ($findings as $finding) {
            if ($finding['level'] === 'critical') {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @return list<array{level: 'ok'|'warning'|'critical', label: string, detail: string}> */
    private function findings(): array
    {
        return [
            $this->environmentFinding(),
            $this->mailerFinding(),
            $this->failoverFinding(),
            $this->middlewareFinding(),
            $this->storageFinding(),
            $this->sqliteFinding(),
            $this->schemaFinding(),
            $this->configCacheFinding(),
            $this->routeCacheFinding(),
            $this->retentionFinding(),
            $this->orphanFinding(),
        ];
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function environmentFinding(): array
    {
        if (! $this->guard->allows()) {
            return $this->finding('critical', 'Environment', (string) $this->guard->reason());
        }

        return $this->finding('ok', 'Environment', (string) $this->laravel->environment());
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function mailerFinding(): array
    {
        $transport = $this->config->get('mail.mailers.local.transport');

        if ($transport !== 'local') {
            return $this->finding('critical', 'Mailer', 'mail.mailers.local.transport must be local.');
        }

        $default = $this->config->get('mail.default');

        if ($default !== 'local') {
            return $this->finding('warning', 'Mailer', 'mail.default is not local; use the local mailer explicitly.');
        }

        return $this->finding('ok', 'Mailer', 'mail.mailers.local uses the local transport.');
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function failoverFinding(): array
    {
        $configured = $this->config->get('mail.mailers', []);
        $composedMailers = [];

        if (is_array($configured)) {
            foreach ($configured as $name => $mailer) {
                if (! is_array($mailer) || ! is_array($mailer['mailers'] ?? null)) {
                    continue;
                }

                foreach ($mailer['mailers'] as $referenced) {
                    if (! is_string($referenced)) {
                        continue;
                    }

                    $referencedMailer = $configured[$referenced] ?? null;

                    if (is_array($referencedMailer) && ($referencedMailer['transport'] ?? null) === 'local') {
                        $composedMailers[] = (string) $name;

                        break;
                    }
                }
            }
        }

        if ($composedMailers !== []) {
            return $this->finding(
                'critical',
                'Failover',
                'A local transport appears in '.implode(', ', array_map(static fn (string $name): string => 'mail.mailers.'.$name.'.mailers', $composedMailers)).'; remove it from failover or round-robin composition.',
            );
        }

        return $this->finding('ok', 'Failover', 'local is not part of a failover or round-robin mailer.');
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function middlewareFinding(): array
    {
        $middleware = $this->config->get('mailbox.middleware', ['web']);

        if (! is_array($middleware) || ! in_array('web', $middleware, true)) {
            return $this->finding('critical', 'Middleware', 'mutations lose CSRF protection because mailbox.middleware does not contain web.');
        }

        return $this->finding('ok', 'Middleware', 'web middleware protects mailbox mutations.');
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function storageFinding(): array
    {
        try {
            $this->paths->ensureRoot();
        } catch (Throwable $exception) {
            return $this->finding('critical', 'Storage', 'Unable to create mailbox storage: '.$exception->getMessage());
        }

        if (! is_writable($this->paths->root)) {
            return $this->finding('critical', 'Storage', 'Mailbox storage is not writable: '.$this->paths->root);
        }

        return $this->finding('ok', 'Storage', $this->paths->root);
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function sqliteFinding(): array
    {
        try {
            $statement = $this->store->pdo()->query('SELECT sqlite_version()');
            $version = $statement === false ? false : $statement->fetchColumn();

            if (! is_string($version)) {
                return $this->finding('critical', 'SQLite', 'Unable to determine sqlite_version().');
            }

            return $this->finding(
                version_compare($version, '3.35', '<') ? 'warning' : 'ok',
                'SQLite',
                'sqlite_version() '.$version,
            );
        } catch (Throwable $exception) {
            return $this->finding('critical', 'SQLite', 'SQLite unavailable: '.$exception->getMessage());
        }
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function schemaFinding(): array
    {
        try {
            return $this->finding('ok', 'Schema', 'Version '.$this->store->schemaVersion().'.');
        } catch (Throwable $exception) {
            return $this->finding('critical', 'Schema', 'Unable to read mailbox schema version: '.$exception->getMessage());
        }
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function configCacheFinding(): array
    {
        if ($this->laravel->configurationIsCached()) {
            return $this->finding('warning', 'Config cache', 'Configuration is cached; environment changes require config:cache again.');
        }

        return $this->finding('ok', 'Config cache', 'Configuration is not cached.');
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function routeCacheFinding(): array
    {
        if ($this->laravel->routesAreCached()) {
            return $this->finding('warning', 'Route cache', 'Routes are cached.');
        }

        return $this->finding('ok', 'Route cache', 'Routes are not cached.');
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function retentionFinding(): array
    {
        try {
            $totals = $this->store->totals();
            $maxMessages = max(1, (int) $this->config->get('mailbox.retention.max_messages', 1000));
            $maxBytes = max(1024 * 1024, (int) $this->config->get('mailbox.retention.max_bytes', 250 * 1024 * 1024));
            $level = $totals['count'] > $maxMessages * 0.9 || $totals['bytes'] > $maxBytes * 0.9 ? 'warning' : 'ok';

            return $this->finding(
                $level,
                'Retention',
                sprintf('%d/%d messages, %d/%d bytes.', $totals['count'], $maxMessages, $totals['bytes'], $maxBytes),
            );
        } catch (Throwable $exception) {
            return $this->finding('critical', 'Retention', 'Unable to read retention totals: '.$exception->getMessage());
        }
    }

    /** @return array{level: 'ok'|'warning'|'critical', label: string, detail: string} */
    private function orphanFinding(): array
    {
        try {
            $scan = $this->repair->scan();
            $hasProblems = $scan['orphan_dirs'] !== [] || $scan['dangling_rows'] !== [] || $scan['stale_tmp'] !== [];

            return $this->finding(
                $hasProblems ? 'warning' : 'ok',
                'Orphans',
                sprintf(
                    '%d orphan directories, %d dangling rows, %d stale tmp entries.',
                    count($scan['orphan_dirs']),
                    count($scan['dangling_rows']),
                    count($scan['stale_tmp']),
                ),
            );
        } catch (Throwable $exception) {
            return $this->finding('critical', 'Orphans', 'Unable to scan orphan state: '.$exception->getMessage());
        }
    }

    /**
     * @param  'ok'|'warning'|'critical'  $level
     * @return array{level: 'ok'|'warning'|'critical', label: string, detail: string}
     */
    private function finding(string $level, string $label, string $detail): array
    {
        return [
            'level' => $level,
            'label' => $label,
            'detail' => $detail,
        ];
    }
}
