# Phase 1 — Capture Kernel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `local` Laravel mail transport that durably captures the exact prepared MIME stream, envelope, original recipients and a structured part tree into a private SQLite + blob store, with fail-closed environment guarding, crash repair, pruning and doctor/clear/prune commands.

**Architecture:** `LocalTransport` (Symfony `AbstractTransport`) hands each `SentMessage` to `MessageRecorder`, which streams `raw.eml` to a staging directory, walks the original `Email` body tree to write decoded part blobs, atomically renames the staging directory under a shared file lock, and inserts one SQLite row per capture. Maintenance (prune/repair/clear) runs under the exclusive lock. Nothing is visible before commit; nothing after commit can fail the send.

**Tech Stack:** PHP 8.2+, Laravel 12/13 (`illuminate/*`), Symfony Mailer/Mime 7.2+/8, PDO SQLite, Orchestra Testbench 10/11, Pest 4/5, Larastan 3, Pint.

**Spec:** `docs/superpowers/specs/2026-08-28-laravel-mailbox-design.md` (implementation design, rev 2) and `docs/superpowers/specs/2026-08-28-product-spec.md` (hard rules §18). Read both before starting any task.

## Global Constraints

- Namespace `Rudisang\Mailbox`, PSR-4 from `src/`; tests under `Rudisang\Mailbox\Tests` from `tests/`. Every PHP file starts with `<?php` + `declare(strict_types=1);`.
- PHP `^8.2`: no PHP 8.3-only syntax (no typed class constants, no `#[\Override]`, no `json_validate`).
- Laravel 12 **and** 13: use only APIs present in both. Never use named arguments when calling Laravel/Symfony methods (parameter names are not covered by their BC promise). Own code may use named arguments only in constructors of own DTOs.
- `env()` only inside `config/mailbox.php`. No closures in config. No `dd`, `dump`, `exit`.
- Never log message bodies, subjects, addresses, filenames or raw MIME. Debug logs may include capture id, byte counts, durations.
- Never persist serialized PHP objects. Never parse MIME bodies with regexes. Never reconstruct `raw.eml` from columns.
- Transport failures must be `Symfony\Component\Mailer\Exception\TransportException` (or a subclass).
- Run `vendor/bin/pint --dirty` before every commit and `vendor/bin/phpstan analyse` before finishing a task; both must be clean.
- Commit with `git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "<type>: <summary>"` (types: feat, fix, test, docs, chore, refactor).
- Tests: `vendor/bin/pest --filter=<Name>` while iterating; `vendor/bin/pest` at task end. Feature tests extend `Rudisang\Mailbox\Tests\TestCase` (already exists; sets `mail.default=local`, per-test temp `mailbox.storage_path`, `APP_ENV=testing`). Unit tests under `tests/Unit` are plain Pest (no Laravel app) unless stated.

---

## File map (Phase 1)

| File | Responsibility |
|---|---|
| `config/mailbox.php` | All configuration (guard, path, storage, namespace, retention, limits) |
| `src/MailboxServiceProvider.php` | Bindings, config merge, `mail.mailers.local` default, `Mail::extend`, listeners, commands, about, publish |
| `src/Mailbox.php` | Static app-facing helpers: `context()`, `redactContextUsing()` |
| `src/Exceptions/MailboxDisabledException.php` | `TransportException` thrown outside allowed environments |
| `src/Exceptions/MessageTooLargeException.php` | `TransportException` when raw exceeds `limits.raw_bytes` |
| `src/Exceptions/CaptureFailedException.php` | `TransportException` wrapping storage failures |
| `src/Support/EnvironmentGuard.php` | `allows()`, `assertAllowed()`, `reason()` |
| `src/Support/StoragePaths.php` | Root + derived paths; `ensureRoot()` |
| `src/Support/Limits.php` | Clamped limits DTO |
| `src/Capture/RawStreamWriter.php` | Iterable → file with byte limit, SHA-256, fsync |
| `src/Capture/RawHeaderBlock.php` | Ordered decoded header pairs from `raw.eml` |
| `src/Capture/FailureInjector.php` | Test-only stage failure hook (no-op by default) |
| `src/Capture/ContextCollector.php` | Send-scoped + job-scoped + app-supplied scalar provenance |
| `src/Capture/MessageRecorder.php` | The capture sequence (§5) |
| `src/Mime/AddressNormalizer.php` | Symfony `Address` → `['address'=>…, 'name'=>…]` |
| `src/Mime/PartWriter.php` | Decoded part body → blob via stream filters |
| `src/Mime/ExtractedPart.php`, `src/Mime/ExtractedMessage.php` | DTOs produced by the extractor |
| `src/Mime/StructuredMessageExtractor.php` | Walks `Email::getBody()` tree under limits |
| `src/Storage/Schema.php` | SQL DDL |
| `src/Storage/MessageRecord.php`, `src/Storage/PartRecord.php` | Row DTOs |
| `src/Storage/MessageStore.php` | PDO SQLite access (insert/find/list/count/status/markRead/delete/clear/maintenance queries) |
| `src/Storage/MaintenanceLock.php` | `flock` shared/exclusive helper |
| `src/Storage/Pruner.php` | Retention enforcement |
| `src/Storage/Repair.php` | Orphan/dangling/stale-tmp scan and repair |
| `src/Transport/LocalTransport.php`, `src/Transport/LocalTransportFactory.php` | Laravel/Symfony seam |
| `src/Events/MessageCaptured.php` | Post-commit event |
| `src/Console/DoctorCommand.php`, `ClearCommand.php`, `PruneCommand.php` | Console |
| `tests/Fixtures/Emails.php` | Static builders of Symfony `Email` fixtures used across suites |

---

### Task 1: Configuration, provider skeleton, environment guard

**Files:**
- Create: `config/mailbox.php`, `src/Support/EnvironmentGuard.php`, `src/Support/Limits.php`, `src/Support/StoragePaths.php`, `src/Exceptions/MailboxDisabledException.php`
- Modify: `src/MailboxServiceProvider.php`
- Test: `tests/Feature/ServiceProviderTest.php`, `tests/Feature/EnvironmentGuardTest.php`, `tests/Unit/LimitsTest.php`

**Interfaces:**
- Produces `EnvironmentGuard::allows(): bool`, `assertAllowed(): void`, `reason(): ?string`.
- Produces `Limits` readonly props `rawBytes, parts, depth, headerBytes, searchTextBytes, previewBytes`; `Limits::fromConfig(array $config): Limits`.
- Produces `StoragePaths` with `root`, `index()`, `lock()`, `messagesDir()`, `tmpDir()`, `tmp(string $id)`, `message(string $id)`, `raw(string $id)`, `partsDir(string $id)`, `part(string $id, string $partId)`, `ensureRoot(): void`; `StoragePaths::fromConfig(Repository $config, Application $app): StoragePaths`.
- Provider binds singletons for `EnvironmentGuard`, `Limits`, `StoragePaths`.

- [ ] **Step 1: Write config**

```php
<?php

declare(strict_types=1);

return [
    // null = decide by environment; false = force off. There is no way to force ON outside allowed environments.
    'enabled' => env('MAILBOX_ENABLED'),

    // Environments in which the transport captures and the UI is reachable. 'production' is always refused.
    'environments' => ['local', 'testing'],

    // URL prefix of the mailbox UI.
    'path' => env('MAILBOX_PATH', '_mailbox'),

    // Middleware applied to every mailbox route (the package adds its own Authorize middleware).
    'middleware' => ['web'],

    // Private storage root. null = storage/framework/mailbox
    'storage_path' => env('MAILBOX_STORAGE_PATH'),

    // Optional test-run namespace attached to every capture in this process.
    'namespace' => env('MAILBOX_NAMESPACE'),

    'retention' => [
        'days' => 7,
        'max_messages' => 1000,
        'max_bytes' => 250 * 1024 * 1024,
    ],

    'limits' => [
        'raw_bytes' => 50 * 1024 * 1024,
        'parts' => 100,
        'depth' => 30,
        'header_bytes' => 256 * 1024,
        'search_text_bytes' => 512 * 1024,
        'preview_bytes' => 2 * 1024 * 1024,
    ],
];
```

- [ ] **Step 2: Write failing tests**

`tests/Feature/ServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Support\EnvironmentGuard;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Support\StoragePaths;

it('merges default configuration', function () {
    expect(config('mailbox.path'))->toBe('_mailbox')
        ->and(config('mailbox.environments'))->toBe(['local', 'testing'])
        ->and(config('mailbox.limits.parts'))->toBe(100);
});

it('adds the local mailer when the application has not defined one', function () {
    expect(config('mail.mailers.local'))->toBe(['transport' => 'local']);
});

it('never overwrites an application-defined local mailer', function () {
    $this->app['config']->set('mail.mailers.local', ['transport' => 'smtp', 'host' => 'example']);
    (new Rudisang\Mailbox\MailboxServiceProvider($this->app))->register();

    expect(config('mail.mailers.local.transport'))->toBe('smtp');
});

it('binds the support singletons', function () {
    expect($this->app->make(EnvironmentGuard::class))->toBe($this->app->make(EnvironmentGuard::class))
        ->and($this->app->make(Limits::class)->parts)->toBe(100)
        ->and($this->app->make(StoragePaths::class)->root)->toBe($this->mailboxStoragePath);
});

it('defaults storage to storage/framework/mailbox', function () {
    $this->app['config']->set('mailbox.storage_path', null);
    $this->app->forgetInstance(StoragePaths::class);

    expect($this->app->make(StoragePaths::class)->root)->toBe(storage_path('framework/mailbox'));
});

it('publishes the config with the mailbox-config tag', function () {
    $this->artisan('vendor:publish', ['--tag' => 'mailbox-config'])->assertSuccessful();

    expect(config_path('mailbox.php'))->toBeFile();
    @unlink(config_path('mailbox.php'));
});
```

`tests/Feature/EnvironmentGuardTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Exceptions\MailboxDisabledException;
use Rudisang\Mailbox\Support\EnvironmentGuard;

function guard(): EnvironmentGuard
{
    return app(EnvironmentGuard::class);
}

it('allows testing and local by default', function () {
    expect(guard()->allows())->toBeTrue();

    $this->app['env'] = 'local';
    expect(guard()->allows())->toBeTrue();
});

it('refuses production even when listed', function () {
    $this->app['env'] = 'production';
    config()->set('mailbox.environments', ['local', 'testing', 'production']);

    expect(guard()->allows())->toBeFalse()
        ->and(guard()->reason())->toBe('environment:production');
});

it('refuses environments that are not listed', function () {
    $this->app['env'] = 'staging';

    expect(guard()->allows())->toBeFalse()->and(guard()->reason())->toBe('environment:staging');
});

it('can only be disabled, never force-enabled, by the enabled flag', function () {
    config()->set('mailbox.enabled', false);
    expect(guard()->allows())->toBeFalse()->and(guard()->reason())->toBe('disabled');

    config()->set('mailbox.enabled', true);
    $this->app['env'] = 'staging';
    expect(guard()->allows())->toBeFalse();
});

it('throws a transport exception when asserting outside allowed environments', function () {
    $this->app['env'] = 'production';

    guard()->assertAllowed();
})->throws(MailboxDisabledException::class);
```

`tests/Unit/LimitsTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Support\Limits;

it('uses defaults when keys are missing', function () {
    $limits = Limits::fromConfig([]);

    expect($limits->rawBytes)->toBe(50 * 1024 * 1024)
        ->and($limits->parts)->toBe(100)
        ->and($limits->depth)->toBe(30)
        ->and($limits->headerBytes)->toBe(256 * 1024)
        ->and($limits->searchTextBytes)->toBe(512 * 1024)
        ->and($limits->previewBytes)->toBe(2 * 1024 * 1024);
});

it('clamps values into safe ranges', function () {
    $limits = Limits::fromConfig(['raw_bytes' => 1, 'parts' => 100000, 'depth' => 0, 'header_bytes' => -5]);

    expect($limits->rawBytes)->toBe(64 * 1024)
        ->and($limits->parts)->toBe(1000)
        ->and($limits->depth)->toBe(1)
        ->and($limits->headerBytes)->toBe(4 * 1024);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php tests/Feature/EnvironmentGuardTest.php tests/Unit/LimitsTest.php`
Expected: failures (classes not found / config missing).

- [ ] **Step 4: Implement**

`src/Support/Limits.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Support;

final class Limits
{
    public function __construct(
        public readonly int $rawBytes,
        public readonly int $parts,
        public readonly int $depth,
        public readonly int $headerBytes,
        public readonly int $searchTextBytes,
        public readonly int $previewBytes,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            self::clamp($config['raw_bytes'] ?? null, 50 * 1024 * 1024, 64 * 1024, 1024 * 1024 * 1024),
            self::clamp($config['parts'] ?? null, 100, 1, 1000),
            self::clamp($config['depth'] ?? null, 30, 1, 100),
            self::clamp($config['header_bytes'] ?? null, 256 * 1024, 4 * 1024, 4 * 1024 * 1024),
            self::clamp($config['search_text_bytes'] ?? null, 512 * 1024, 1024, 8 * 1024 * 1024),
            self::clamp($config['preview_bytes'] ?? null, 2 * 1024 * 1024, 16 * 1024, 32 * 1024 * 1024),
        );
    }

    private static function clamp(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
```

`src/Support/StoragePaths.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final class StoragePaths
{
    public function __construct(public readonly string $root) {}

    public static function fromConfig(Repository $config, Application $app): self
    {
        $root = $config->get('mailbox.storage_path');

        if (! is_string($root) || $root === '') {
            $root = $app->storagePath('framework'.DIRECTORY_SEPARATOR.'mailbox');
        }

        return new self(rtrim($root, '/\\'));
    }

    public function index(): string { return $this->root.DIRECTORY_SEPARATOR.'index.sqlite'; }
    public function lock(): string { return $this->root.DIRECTORY_SEPARATOR.'.lock'; }
    public function messagesDir(): string { return $this->root.DIRECTORY_SEPARATOR.'messages'; }
    public function tmpDir(): string { return $this->root.DIRECTORY_SEPARATOR.'tmp'; }
    public function tmp(string $id): string { return $this->tmpDir().DIRECTORY_SEPARATOR.$id; }
    public function message(string $id): string { return $this->messagesDir().DIRECTORY_SEPARATOR.$id; }
    public function raw(string $id): string { return $this->message($id).DIRECTORY_SEPARATOR.'raw.eml'; }
    public function partsDir(string $id): string { return $this->message($id).DIRECTORY_SEPARATOR.'parts'; }
    public function part(string $id, string $partId): string { return $this->partsDir($id).DIRECTORY_SEPARATOR.$partId.'.bin'; }

    public function ensureRoot(): void
    {
        foreach ([$this->root, $this->messagesDir(), $this->tmpDir()] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException('Mailbox storage directory could not be created.');
            }
        }
    }
}
```

`src/Exceptions/MailboxDisabledException.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

final class MailboxDisabledException extends TransportException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('The "local" mailbox transport is disabled (%s). It only runs in the environments listed in config("mailbox.environments").', $reason));
    }
}
```

`src/Support/EnvironmentGuard.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Rudisang\Mailbox\Exceptions\MailboxDisabledException;

final class EnvironmentGuard
{
    public function __construct(private readonly Application $app, private readonly Repository $config) {}

    public function allows(): bool
    {
        return $this->reason() === null;
    }

    /** Returns null when allowed, otherwise a short machine-readable reason. */
    public function reason(): ?string
    {
        if ($this->config->get('mailbox.enabled') === false) {
            return 'disabled';
        }

        $environment = (string) $this->app->environment();
        $allowed = $this->config->get('mailbox.environments', ['local', 'testing']);
        $allowed = is_array($allowed) ? array_values(array_filter($allowed, 'is_string')) : ['local', 'testing'];

        if ($environment === 'production' || ! in_array($environment, $allowed, true)) {
            return 'environment:'.$environment;
        }

        return null;
    }

    public function assertAllowed(): void
    {
        $reason = $this->reason();

        if ($reason !== null) {
            throw MailboxDisabledException::because($reason);
        }
    }
}
```

`src/MailboxServiceProvider.php` (this task's version; later tasks extend it):

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Rudisang\Mailbox\Support\EnvironmentGuard;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Support\StoragePaths;

class MailboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailbox.php', 'mailbox');

        /** @var Repository $config */
        $config = $this->app->make('config');

        if ($config->get('mail.mailers.local') === null) {
            $config->set('mail.mailers.local', ['transport' => 'local']);
        }

        $this->app->singleton(EnvironmentGuard::class, fn (Application $app) => new EnvironmentGuard($app, $app->make('config')));
        $this->app->singleton(Limits::class, fn (Application $app) => Limits::fromConfig((array) $app->make('config')->get('mailbox.limits', [])));
        $this->app->singleton(StoragePaths::class, fn (Application $app) => StoragePaths::fromConfig($app->make('config'), $app));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mailbox.php' => $this->app->configPath('mailbox.php'),
            ], ['mailbox', 'mailbox-config']);

            AboutCommand::add('Mailbox', fn () => [
                'Enabled' => $this->app->make(EnvironmentGuard::class)->allows() ? 'Yes' : 'No ('.$this->app->make(EnvironmentGuard::class)->reason().')',
                'Path' => '/'.trim((string) $this->app->make('config')->get('mailbox.path', '_mailbox'), '/'),
                'Storage' => $this->app->make(StoragePaths::class)->root,
            ]);
        }
    }
}
```

- [ ] **Step 5: Run tests, Pint, PHPStan**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php tests/Feature/EnvironmentGuardTest.php tests/Unit/LimitsTest.php && vendor/bin/pint --dirty && vendor/bin/phpstan analyse`
Expected: all pass, clean.

- [ ] **Step 6: Commit**

```bash
git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: configuration, provider skeleton and fail-closed environment guard"
```

---

### Task 2: Raw stream writer and decoded part writer (streaming spike)

**Files:**
- Create: `src/Capture/RawStreamWriter.php`, `src/Mime/PartWriter.php`, `src/Exceptions/MessageTooLargeException.php`
- Test: `tests/Unit/RawStreamWriterTest.php`, `tests/Unit/PartWriterTest.php`

**Interfaces:**
- Produces `RawStreamWriter::write(iterable<string> $chunks, string $path, int $maxBytes): array{bytes:int, sha256:string}`; throws `MessageTooLargeException`.
- Produces `PartWriter::write(\Symfony\Component\Mime\Part\AbstractPart $part, string $path): array{bytes:int, sha256:string}` — writes the **decoded** body.

- [ ] **Step 1: Write failing tests**

`tests/Unit/RawStreamWriterTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Capture\RawStreamWriter;
use Rudisang\Mailbox\Exceptions\MessageTooLargeException;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/mailbox-raw-'.bin2hex(random_bytes(4)).'.eml';
});

afterEach(fn () => @unlink($this->path));

it('streams chunks to disk and reports size and hash', function () {
    $result = RawStreamWriter::write(['Subject: hi', "\r\n", "\r\n", 'body'], $this->path, 1024);

    expect(file_get_contents($this->path))->toBe("Subject: hi\r\n\r\nbody")
        ->and($result['bytes'])->toBe(19)
        ->and($result['sha256'])->toBe(hash('sha256', "Subject: hi\r\n\r\nbody"));
});

it('aborts and removes the file when the limit is exceeded', function () {
    $generator = (function () {
        for ($i = 0; $i < 100; $i++) {
            yield str_repeat('x', 100);
        }
    })();

    expect(fn () => RawStreamWriter::write($generator, $this->path, 500))
        ->toThrow(MessageTooLargeException::class)
        ->and(file_exists($this->path))->toBeFalse();
});

it('does not buffer the whole message in memory', function () {
    $generator = (function () {
        for ($i = 0; $i < 2000; $i++) {
            yield str_repeat('y', 8192); // 16 MiB total
        }
    })();
    $before = memory_get_peak_usage(true);

    RawStreamWriter::write($generator, $this->path, 64 * 1024 * 1024);

    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(8 * 1024 * 1024)
        ->and(filesize($this->path))->toBe(2000 * 8192);
});
```

`tests/Unit/PartWriterTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Mime\PartWriter;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\TextPart;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/mailbox-part-'.bin2hex(random_bytes(4)).'.bin';
});

afterEach(fn () => @unlink($this->path));

it('decodes base64 data parts back to the original bytes', function () {
    $binary = random_bytes(100_000);
    $part = new DataPart($binary, 'blob.bin', 'application/octet-stream');

    $result = PartWriter::write($part, $this->path);

    expect(file_get_contents($this->path))->toBe($binary)
        ->and($result['bytes'])->toBe(100_000)
        ->and($result['sha256'])->toBe(hash('sha256', $binary));
});

it('decodes quoted-printable text parts', function () {
    $text = "Héllo wörld — a very long line ".str_repeat('with soft breaks ', 20)."\nsecond line";
    $part = new TextPart($text, 'utf-8', 'plain', 'quoted-printable');

    PartWriter::write($part, $this->path);

    expect(file_get_contents($this->path))->toBe($text);
});

it('passes 8bit text parts through unchanged', function () {
    $part = new TextPart("plain\r\ntext", 'utf-8', 'plain', '8bit');

    PartWriter::write($part, $this->path);

    expect(file_get_contents($this->path))->toBe("plain\r\ntext");
});

it('streams file-backed parts without loading them into memory', function () {
    $source = sys_get_temp_dir().'/mailbox-src-'.bin2hex(random_bytes(4)).'.bin';
    $fh = fopen($source, 'wb');
    for ($i = 0; $i < 1280; $i++) {
        fwrite($fh, random_bytes(8192)); // 10 MiB
    }
    fclose($fh);
    $part = new DataPart(new File($source), 'big.bin', 'application/octet-stream');
    $before = memory_get_peak_usage(true);

    $result = PartWriter::write($part, $this->path);

    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(12 * 1024 * 1024)
        ->and($result['bytes'])->toBe(1280 * 8192)
        ->and(hash_file('sha256', $this->path))->toBe(hash_file('sha256', $source));
    @unlink($source);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/RawStreamWriterTest.php tests/Unit/PartWriterTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

`src/Exceptions/MessageTooLargeException.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

final class MessageTooLargeException extends TransportException
{
    public static function limit(int $limit): self
    {
        return new self(sprintf('The message exceeds the mailbox raw size limit of %d bytes (config mailbox.limits.raw_bytes).', $limit));
    }
}
```

`src/Capture/RawStreamWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

use Rudisang\Mailbox\Exceptions\MessageTooLargeException;
use RuntimeException;

final class RawStreamWriter
{
    /**
     * @param  iterable<string>  $chunks
     * @return array{bytes: int, sha256: string}
     */
    public static function write(iterable $chunks, string $path, int $maxBytes): array
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the raw message file for writing.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            foreach ($chunks as $chunk) {
                $chunk = (string) $chunk;
                $bytes += strlen($chunk);

                if ($bytes > $maxBytes) {
                    throw MessageTooLargeException::limit($maxBytes);
                }

                hash_update($hash, $chunk);

                if (fwrite($handle, $chunk) === false) {
                    throw new RuntimeException('Unable to write the raw message file.');
                }
            }

            fflush($handle);
            fsync($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        }

        fclose($handle);

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }
}
```

`src/Mime/PartWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

use RuntimeException;
use Symfony\Component\Mime\Part\AbstractPart;

final class PartWriter
{
    /** @return array{bytes: int, sha256: string} */
    public static function write(AbstractPart $part, string $path): array
    {
        $encoding = strtolower(trim($part->getPreparedHeaders()->getHeaderBody('Content-Transfer-Encoding') ?? ''));

        $filter = match ($encoding) {
            'base64' => 'convert.base64-decode',
            'quoted-printable' => 'convert.quoted-printable-decode',
            default => null,
        };

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the part file for writing.');
        }

        try {
            if ($filter !== null && stream_filter_append($handle, $filter, STREAM_FILTER_WRITE) === false) {
                throw new RuntimeException('Unable to attach the decoding stream filter.');
            }

            foreach ($part->bodyToIterable() as $chunk) {
                $chunk = (string) $chunk;

                if ($filter === 'convert.base64-decode') {
                    $chunk = str_replace(["\r", "\n"], '', $chunk);
                }

                if ($chunk !== '' && fwrite($handle, $chunk) === false) {
                    throw new RuntimeException('Unable to write the part file.');
                }
            }

            fflush($handle);
            fsync($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        }

        fclose($handle);

        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);

        if ($bytes === false || $sha256 === false) {
            throw new RuntimeException('Unable to read back the part file.');
        }

        return ['bytes' => $bytes, 'sha256' => $sha256];
    }
}
```

Note for the implementer: `Headers::getHeaderBody()` returns `mixed`; cast defensively (`is_string($body) ? $body : ''`). If the base64 filter rejects line breaks in your PHP build even after the `str_replace`, keep the replace — it strips only CR/LF (transport line folding), which is not MIME parsing.

- [ ] **Step 4: Run tests, Pint, PHPStan**

Run: `vendor/bin/pest tests/Unit/RawStreamWriterTest.php tests/Unit/PartWriterTest.php && vendor/bin/pint --dirty && vendor/bin/phpstan analyse`
Expected: PASS. If the memory assertions fail, the implementation is buffering — fix it, do not loosen the thresholds.

- [ ] **Step 5: Commit**

```bash
git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: streaming raw and decoded part writers"
```

---

### Task 3: SQLite message store, schema, lock

**Files:**
- Create: `src/Storage/Schema.php`, `src/Storage/MessageRecord.php`, `src/Storage/PartRecord.php`, `src/Storage/MessageStore.php`, `src/Storage/MaintenanceLock.php`
- Modify: `src/MailboxServiceProvider.php` (bind `MessageStore`, `MaintenanceLock` singletons)
- Test: `tests/Unit/MessageStoreTest.php`, `tests/Unit/MaintenanceLockTest.php`

**Interfaces:**
- `MessageRecord` readonly public props: `?int $seq, string $id, string $capturedAt (ISO-8601 UTC), ?string $messageId, string $rawSha256, int $rawBytes, ?string $mailer, string $parseStatus ('ok'|'partial'|'failed'|'unsupported'), ?string $parseError, ?string $subject, list<array{address:string,name:string}> $from,$to,$cc,$bcc,$replyTo, ?string $envelopeSender, list<string> $envelopeRecipients, list<string> $tags, array<string,string> $metadata, list<array{0:string,1:string}> $rawHeaders, bool $hasHtml, bool $hasText, ?string $previewText, ?string $searchText, int $partCount, int $attachmentCount, int $decodedBytes, ?string $readAt, ?string $namespace, array<string,string|null> $context`. Constructor takes them in that order; `MessageRecord::fromRow(array $row): self`; `isRead(): bool`.
- `PartRecord` readonly: `string $id, string $messageId, ?string $parentId, int $position, int $depth, string $contentType, string $mediaType, string $mediaSubtype, ?string $disposition, ?string $filename, ?string $contentId, ?string $charset, ?string $transferEncoding, int $decodedBytes, ?string $sha256, bool $isInline, bool $isAttachment`; `fromRow(array $row): self`; `isLeaf(): bool` (media type !== 'multipart').
- `MessageStore::__construct(StoragePaths $paths, MaintenanceLock $lock)`; `pdo(): PDO`; `insert(MessageRecord $m, list<PartRecord> $parts): int` (returns seq); `find(string $id): ?MessageRecord`; `parts(string $id): list<PartRecord>`; `findPart(string $messageId, string $partId): ?PartRecord`; `list(array $filters = []): list<MessageRecord>` filters: `q, unread, attachments, issues, namespace, limit (default 50), offset`; `count(array $filters = []): int`; `status(?string $namespace): array{seq:int,total:int,unread:int}`; `countSince(int $seq, ?string $namespace): int`; `markRead(string $id, bool $read): void`; `delete(string $id): void` (row + directory); `clear(): void` (all rows + directories); `allIds(): list<string>`; `idsOlderThan(string $isoUtc): list<string>`; `idsBeyondCount(int $keep): list<string>` (oldest first, beyond the newest `$keep`); `idsBeyondBytes(int $maxBytes): list<string>` (oldest first until the remaining total fits); `totals(): array{count:int, bytes:int}`.
- `MaintenanceLock::__construct(string $path)`; `shared(callable $fn): mixed`; `exclusive(callable $fn, bool $blocking = true): mixed` (returns `null` without calling when non-blocking and busy).

- [ ] **Step 1: Write failing tests**

`tests/Unit/MaintenanceLockTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Storage\MaintenanceLock;

it('runs callbacks under shared and exclusive locks and returns their value', function () {
    $lock = new MaintenanceLock(sys_get_temp_dir().'/mailbox-lock-'.bin2hex(random_bytes(4)));

    expect($lock->shared(fn () => 'shared'))->toBe('shared')
        ->and($lock->exclusive(fn () => 'exclusive'))->toBe('exclusive');
});

it('skips a non-blocking exclusive callback while another process holds the lock', function () {
    $path = sys_get_temp_dir().'/mailbox-lock-'.bin2hex(random_bytes(4));
    $holder = proc_open([PHP_BINARY, '-r', '$h=fopen($argv[1],"c+");flock($h,LOCK_SH);echo "held\n";fflush(STDOUT);fgets(STDIN);', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]); // wait until the child holds the shared lock
    $lock = new MaintenanceLock($path);

    expect($lock->exclusive(fn () => 'ran', false))->toBeNull();

    fwrite($pipes[0], "\n");
    proc_close($holder);
    expect($lock->exclusive(fn () => 'ran', false))->toBe('ran');
});
```

`tests/Unit/MessageStoreTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Storage\MaintenanceLock;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\PartRecord;
use Rudisang\Mailbox\Support\StoragePaths;

function makeRecord(array $overrides = []): MessageRecord
{
    $id = $overrides['id'] ?? strtoupper(substr(str_replace(['-', '.'], '', uniqid('', true)), 0, 26));
    $id = str_pad(preg_replace('/[^0-9A-HJKMNP-TV-Z]/', '0', $id), 26, '0');

    return MessageRecord::fromRow(array_merge([
        'seq' => null,
        'id' => $id,
        'captured_at' => '2026-08-28T10:00:00Z',
        'message_id' => 'abc@example.test',
        'raw_sha256' => str_repeat('a', 64),
        'raw_bytes' => 100,
        'mailer' => 'local',
        'parse_status' => 'ok',
        'parse_error' => null,
        'subject' => 'Hello there',
        'from_json' => json_encode([['address' => 'a@example.com', 'name' => 'A']]),
        'to_json' => json_encode([['address' => 'b@example.com', 'name' => '']]),
        'cc_json' => '[]',
        'bcc_json' => '[]',
        'reply_to_json' => '[]',
        'envelope_sender' => 'a@example.com',
        'envelope_recipients_json' => json_encode(['b@example.com']),
        'tags_json' => '[]',
        'metadata_json' => '{}',
        'raw_headers_json' => json_encode([['Subject', 'Hello there']]),
        'has_html' => 1,
        'has_text' => 0,
        'preview_text' => 'Hello there body',
        'search_text' => 'hello there body b@example.com',
        'part_count' => 1,
        'attachment_count' => 0,
        'decoded_bytes' => 10,
        'read_at' => null,
        'namespace' => null,
        'context_json' => '{}',
    ], $overrides));
}

beforeEach(function () {
    $this->paths = new StoragePaths(sys_get_temp_dir().'/mailbox-store-'.bin2hex(random_bytes(4)));
    $this->paths->ensureRoot();
    $this->store = new MessageStore($this->paths, new MaintenanceLock($this->paths->lock()));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->paths->root));
});

it('creates the schema lazily and inserts a message with parts', function () {
    $record = makeRecord();
    $part = PartRecord::fromRow(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'message_id' => $record->id, 'parent_id' => null, 'position' => 0, 'depth' => 0, 'content_type' => 'text/html; charset=utf-8', 'media_type' => 'text', 'media_subtype' => 'html', 'disposition' => null, 'filename' => null, 'content_id' => null, 'charset' => 'utf-8', 'transfer_encoding' => 'quoted-printable', 'decoded_bytes' => 10, 'sha256' => str_repeat('b', 64), 'is_inline' => 0, 'is_attachment' => 0]);

    $seq = $this->store->insert($record, [$part]);
    $found = $this->store->find($record->id);

    expect($seq)->toBe(1)
        ->and($found)->not->toBeNull()
        ->and($found->subject)->toBe('Hello there')
        ->and($found->from[0]['address'])->toBe('a@example.com')
        ->and($found->rawHeaders[0])->toBe(['Subject', 'Hello there'])
        ->and($this->store->parts($record->id))->toHaveCount(1)
        ->and($this->store->findPart($record->id, $part->id)?->mediaSubtype)->toBe('html')
        ->and($this->store->status(null))->toBe(['seq' => 1, 'total' => 1, 'unread' => 1]);
});

it('lists newest first, filters, searches and counts', function () {
    $this->store->insert(makeRecord(['subject' => 'First', 'search_text' => 'first alpha']), []);
    $this->store->insert(makeRecord(['subject' => 'Second', 'search_text' => 'second beta', 'attachment_count' => 2]), []);
    $this->store->insert(makeRecord(['subject' => 'Third', 'search_text' => 'third gamma', 'parse_status' => 'partial', 'namespace' => 'ns-1']), []);

    expect(array_map(fn ($m) => $m->subject, $this->store->list()))->toBe(['Third', 'Second', 'First'])
        ->and($this->store->list(['q' => 'BETA']))->toHaveCount(1)
        ->and($this->store->list(['attachments' => true])[0]->subject)->toBe('Second')
        ->and($this->store->list(['issues' => true])[0]->subject)->toBe('Third')
        ->and($this->store->list(['namespace' => 'ns-1']))->toHaveCount(1)
        ->and($this->store->count(['q' => 'nothing']))->toBe(0)
        ->and($this->store->list(['limit' => 1, 'offset' => 1])[0]->subject)->toBe('Second')
        ->and($this->store->countSince(1, null))->toBe(2)
        ->and($this->store->countSince(2, 'ns-1'))->toBe(1);
});

it('marks read, deletes rows with their directories and clears everything', function () {
    $a = makeRecord();
    $b = makeRecord();
    $this->store->insert($a, []);
    $this->store->insert($b, []);
    mkdir($this->paths->message($a->id), 0755, true);
    file_put_contents($this->paths->raw($a->id), 'raw');

    $this->store->markRead($a->id, true);
    expect($this->store->find($a->id)->isRead())->toBeTrue()->and($this->store->status(null)['unread'])->toBe(1);

    $this->store->delete($a->id);
    expect($this->store->find($a->id))->toBeNull()->and(is_dir($this->paths->message($a->id)))->toBeFalse();

    $this->store->clear();
    expect($this->store->count())->toBe(0);
});

it('answers the maintenance queries', function () {
    $old = makeRecord(['captured_at' => '2020-01-01T00:00:00Z', 'raw_bytes' => 600]);
    $mid = makeRecord(['captured_at' => '2026-01-01T00:00:00Z', 'raw_bytes' => 300]);
    $new = makeRecord(['captured_at' => '2026-08-28T00:00:00Z', 'raw_bytes' => 100]);
    foreach ([$old, $mid, $new] as $r) {
        $this->store->insert($r, []);
    }

    expect($this->store->totals())->toBe(['count' => 3, 'bytes' => 1000])
        ->and($this->store->idsOlderThan('2025-01-01T00:00:00Z'))->toBe([$old->id])
        ->and($this->store->idsBeyondCount(1))->toBe([$old->id, $mid->id])
        ->and($this->store->idsBeyondBytes(450))->toBe([$old->id, $mid->id])
        ->and($this->store->allIds())->toHaveCount(3);
});

it('survives concurrent schema initialisation and inserts from several processes', function () {
    $script = <<<'PHP'
    require $argv[1].'/vendor/autoload.php';
    $paths = new Rudisang\Mailbox\Support\StoragePaths($argv[2]);
    $store = new Rudisang\Mailbox\Storage\MessageStore($paths, new Rudisang\Mailbox\Storage\MaintenanceLock($paths->lock()));
    for ($i = 0; $i < 25; $i++) {
        $id = strtoupper(bin2hex(random_bytes(13)));
        $id = substr(preg_replace('/[^0-9A-HJKMNP-TV-Z]/', '7', $id), 0, 26);
        $store->insert(Rudisang\Mailbox\Storage\MessageRecord::fromRow(['seq' => null, 'id' => $id, 'captured_at' => gmdate('c'), 'message_id' => null, 'raw_sha256' => str_repeat('c', 64), 'raw_bytes' => 1, 'mailer' => null, 'parse_status' => 'ok', 'parse_error' => null, 'subject' => 'p', 'from_json' => '[]', 'to_json' => '[]', 'cc_json' => '[]', 'bcc_json' => '[]', 'reply_to_json' => '[]', 'envelope_sender' => null, 'envelope_recipients_json' => '[]', 'tags_json' => '[]', 'metadata_json' => '{}', 'raw_headers_json' => '[]', 'has_html' => 0, 'has_text' => 0, 'preview_text' => null, 'search_text' => null, 'part_count' => 0, 'attachment_count' => 0, 'decoded_bytes' => 0, 'read_at' => null, 'namespace' => null, 'context_json' => '{}']), []);
    }
    echo "ok";
    PHP;
    $file = $this->paths->root.'/worker.php';
    file_put_contents($file, "<?php\n".$script);
    $procs = [];
    for ($p = 0; $p < 4; $p++) {
        $procs[] = proc_open([PHP_BINARY, $file, dirname(__DIR__, 2), $this->paths->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$p]);
    }
    $outputs = [];
    foreach ($procs as $i => $proc) {
        $outputs[] = stream_get_contents($pipes[$i][1]).stream_get_contents($pipes[$i][2]);
        proc_close($proc);
    }

    expect($outputs)->each->toBe('ok')
        ->and($this->store->count())->toBe(100);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/MessageStoreTest.php tests/Unit/MaintenanceLockTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`src/Storage/MaintenanceLock.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

use RuntimeException;

final class MaintenanceLock
{
    public function __construct(private readonly string $path) {}

    public function shared(callable $fn): mixed
    {
        return $this->run(LOCK_SH, $fn);
    }

    public function exclusive(callable $fn, bool $blocking = true): mixed
    {
        return $this->run($blocking ? LOCK_EX : LOCK_EX | LOCK_NB, $fn);
    }

    private function run(int $operation, callable $fn): mixed
    {
        $handle = @fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the mailbox lock file.');
        }

        try {
            if (! flock($handle, $operation)) {
                return null;
            }

            try {
                return $fn();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
```

`src/Storage/Schema.php`:

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

final class Schema
{
    public const VERSION = 1;

    /** @return list<string> */
    public static function statements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS mailbox_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS messages (
                seq INTEGER PRIMARY KEY AUTOINCREMENT,
                id TEXT NOT NULL UNIQUE,
                schema_version INTEGER NOT NULL,
                captured_at TEXT NOT NULL,
                message_id TEXT NULL,
                raw_sha256 TEXT NOT NULL,
                raw_bytes INTEGER NOT NULL,
                mailer TEXT NULL,
                parse_status TEXT NOT NULL,
                parse_error TEXT NULL,
                subject TEXT NULL,
                from_json TEXT NOT NULL,
                to_json TEXT NOT NULL,
                cc_json TEXT NOT NULL,
                bcc_json TEXT NOT NULL,
                reply_to_json TEXT NOT NULL,
                envelope_sender TEXT NULL,
                envelope_recipients_json TEXT NOT NULL,
                tags_json TEXT NOT NULL,
                metadata_json TEXT NOT NULL,
                raw_headers_json TEXT NOT NULL,
                has_html INTEGER NOT NULL,
                has_text INTEGER NOT NULL,
                preview_text TEXT NULL,
                search_text TEXT NULL,
                part_count INTEGER NOT NULL,
                attachment_count INTEGER NOT NULL,
                decoded_bytes INTEGER NOT NULL,
                read_at TEXT NULL,
                namespace TEXT NULL,
                context_json TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS messages_captured_at ON messages (captured_at)',
            'CREATE INDEX IF NOT EXISTS messages_namespace_seq ON messages (namespace, seq)',
            'CREATE INDEX IF NOT EXISTS messages_message_id ON messages (message_id)',
            'CREATE TABLE IF NOT EXISTS parts (
                id TEXT PRIMARY KEY,
                message_id TEXT NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
                parent_id TEXT NULL,
                position INTEGER NOT NULL,
                depth INTEGER NOT NULL,
                content_type TEXT NOT NULL,
                media_type TEXT NOT NULL,
                media_subtype TEXT NOT NULL,
                disposition TEXT NULL,
                filename TEXT NULL,
                content_id TEXT NULL,
                charset TEXT NULL,
                transfer_encoding TEXT NULL,
                decoded_bytes INTEGER NOT NULL,
                sha256 TEXT NULL,
                is_inline INTEGER NOT NULL,
                is_attachment INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS parts_message ON parts (message_id, position)',
            "INSERT OR IGNORE INTO mailbox_meta (key, value) VALUES ('schema_version', '".self::VERSION."')",
        ];
    }
}
```

`src/Storage/MessageRecord.php` — constructor with the props listed in Interfaces (in that order), plus:

```php
    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $json = static fn (mixed $v, mixed $default) => is_string($v) && $v !== '' ? (json_decode($v, true) ?? $default) : $default;

        return new self(
            isset($row['seq']) ? (int) $row['seq'] : null,
            (string) $row['id'],
            (string) $row['captured_at'],
            isset($row['message_id']) ? (string) $row['message_id'] : null,
            (string) $row['raw_sha256'],
            (int) $row['raw_bytes'],
            isset($row['mailer']) ? (string) $row['mailer'] : null,
            (string) ($row['parse_status'] ?? 'ok'),
            isset($row['parse_error']) ? (string) $row['parse_error'] : null,
            isset($row['subject']) ? (string) $row['subject'] : null,
            $json($row['from_json'] ?? null, []),
            $json($row['to_json'] ?? null, []),
            $json($row['cc_json'] ?? null, []),
            $json($row['bcc_json'] ?? null, []),
            $json($row['reply_to_json'] ?? null, []),
            isset($row['envelope_sender']) ? (string) $row['envelope_sender'] : null,
            $json($row['envelope_recipients_json'] ?? null, []),
            $json($row['tags_json'] ?? null, []),
            $json($row['metadata_json'] ?? null, []),
            $json($row['raw_headers_json'] ?? null, []),
            (bool) ($row['has_html'] ?? false),
            (bool) ($row['has_text'] ?? false),
            isset($row['preview_text']) ? (string) $row['preview_text'] : null,
            isset($row['search_text']) ? (string) $row['search_text'] : null,
            (int) ($row['part_count'] ?? 0),
            (int) ($row['attachment_count'] ?? 0),
            (int) ($row['decoded_bytes'] ?? 0),
            isset($row['read_at']) ? (string) $row['read_at'] : null,
            isset($row['namespace']) ? (string) $row['namespace'] : null,
            $json($row['context_json'] ?? null, []),
        );
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /** @return array<string, mixed> column => value, for INSERT */
    public function toRow(): array
    {
        return [
            'id' => $this->id, 'schema_version' => Schema::VERSION, 'captured_at' => $this->capturedAt, 'message_id' => $this->messageId,
            'raw_sha256' => $this->rawSha256, 'raw_bytes' => $this->rawBytes, 'mailer' => $this->mailer, 'parse_status' => $this->parseStatus,
            'parse_error' => $this->parseError, 'subject' => $this->subject,
            'from_json' => json_encode($this->from), 'to_json' => json_encode($this->to), 'cc_json' => json_encode($this->cc),
            'bcc_json' => json_encode($this->bcc), 'reply_to_json' => json_encode($this->replyTo),
            'envelope_sender' => $this->envelopeSender, 'envelope_recipients_json' => json_encode($this->envelopeRecipients),
            'tags_json' => json_encode($this->tags), 'metadata_json' => json_encode($this->metadata, JSON_FORCE_OBJECT),
            'raw_headers_json' => json_encode($this->rawHeaders), 'has_html' => (int) $this->hasHtml, 'has_text' => (int) $this->hasText,
            'preview_text' => $this->previewText, 'search_text' => $this->searchText, 'part_count' => $this->partCount,
            'attachment_count' => $this->attachmentCount, 'decoded_bytes' => $this->decodedBytes, 'read_at' => $this->readAt,
            'namespace' => $this->namespace, 'context_json' => json_encode($this->context, JSON_FORCE_OBJECT),
        ];
    }
```

Use `JSON_INVALID_UTF8_SUBSTITUTE` on every `json_encode` so hostile bytes never make `json_encode` return `false`; if it still returns `false`, store `'[]'`/`'{}'`.

`src/Storage/PartRecord.php` — same shape (`fromRow`, `toRow`, `isLeaf()`).

`src/Storage/MessageStore.php` — key points (write the full class):

```php
final class MessageStore
{
    private ?PDO $pdo = null;

    public function __construct(private readonly StoragePaths $paths, private readonly MaintenanceLock $lock) {}

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $this->paths->ensureRoot();
        $pdo = new PDO('sqlite:'.$this->paths->index(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA synchronous = FULL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $this->lock->exclusive(function () use ($pdo): void {
            foreach (Schema::statements() as $sql) {
                $pdo->exec($sql);
            }
        });

        return $this->pdo = $pdo;
    }

    public function insert(MessageRecord $message, array $parts): int
    {
        return $this->retry(function () use ($message, $parts): int {
            $pdo = $this->pdo();
            $pdo->beginTransaction();
            try {
                $row = $message->toRow();
                $pdo->prepare('INSERT INTO messages ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_map(fn ($c) => ':'.$c, array_keys($row))).')')->execute($row);
                $seq = (int) $pdo->lastInsertId();
                $stmt = null;
                foreach ($parts as $part) {
                    $prow = $part->toRow();
                    $stmt ??= $pdo->prepare('INSERT INTO parts ('.implode(',', array_keys($prow)).') VALUES ('.implode(',', array_map(fn ($c) => ':'.$c, array_keys($prow))).')');
                    $stmt->execute($prow);
                }
                $pdo->commit();

                return $seq;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
    }

    /** Retries SQLITE_BUSY / SQLITE_LOCKED with jitter; 3 attempts on top of busy_timeout. */
    private function retry(callable $fn): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (PDOException $e) {
                $busy = str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'database table is locked');
                if (! $busy || ++$attempt >= 3) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000));
            }
        }
    }
```

`list()` builds `WHERE` from filters: `q` → `(subject LIKE :q OR search_text LIKE :q OR message_id LIKE :q)` with `%` escaped via `ESCAPE '\'`; `unread` → `read_at IS NULL`; `attachments` → `attachment_count > 0`; `issues` → `parse_status <> 'ok'`; `namespace` → `namespace = :ns`; order `seq DESC`; `LIMIT :limit OFFSET :offset` with limit clamped to 1..200. `status()` → `SELECT COALESCE(MAX(seq),0), COUNT(*), SUM(read_at IS NULL)` optionally filtered by namespace. `countSince($seq, $ns)` → `COUNT(*) WHERE seq > :seq [AND namespace = :ns]`. `delete($id)` → delete row (cascade) then `self::removeDirectory($this->paths->message($id))` (recursive unlink; ignore missing). `clear()` → `DELETE FROM messages` + remove every directory under `messagesDir()`. `idsBeyondCount($keep)` → `SELECT id FROM messages ORDER BY seq DESC LIMIT -1 OFFSET :keep` then reverse to oldest-first. `idsBeyondBytes($max)` → iterate `SELECT id, raw_bytes, decoded_bytes ORDER BY seq ASC` accumulating from the *newest* side: compute `total = totals()['bytes']`, walk oldest-first collecting ids while `total > $max`, subtracting each row's bytes. `totals()['bytes']` = `SUM(raw_bytes + decoded_bytes)`. `removeDirectory()` is a `public static` helper reused by `Repair`.

Bind in the provider: `$this->app->singleton(MaintenanceLock::class, fn ($app) => new MaintenanceLock($app->make(StoragePaths::class)->lock()));` and `$this->app->singleton(MessageStore::class, fn ($app) => new MessageStore($app->make(StoragePaths::class), $app->make(MaintenanceLock::class)));`.

- [ ] **Step 4: Run tests, Pint, PHPStan**

Run: `vendor/bin/pest tests/Unit/MessageStoreTest.php tests/Unit/MaintenanceLockTest.php && vendor/bin/pint --dirty && vendor/bin/phpstan analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: sqlite message store with schema, lock and maintenance queries"
```

---

### Task 4: Raw header block reader

**Files:**
- Create: `src/Capture/RawHeaderBlock.php`
- Test: `tests/Unit/RawHeaderBlockTest.php`

**Interfaces:**
- `RawHeaderBlock::read(string $rawPath, int $maxBytes): list<array{0:string,1:string}>` — ordered `[name, decodedValue]`; duplicates preserved; value decoded with `iconv_mime_decode` (fall back to the raw value when decoding fails); reading stops at the first empty line or at `$maxBytes`.
- `RawHeaderBlock::has(array $headers, string $name): bool`, `RawHeaderBlock::first(array $headers, string $name): ?string`, `RawHeaderBlock::all(array $headers, string $name): list<string>` (case-insensitive).

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Capture\RawHeaderBlock;

function rawFile(string $content): string
{
    $path = sys_get_temp_dir().'/mailbox-hdr-'.bin2hex(random_bytes(4)).'.eml';
    file_put_contents($path, $content);

    return $path;
}

it('reads ordered headers, unfolds continuations and decodes encoded words', function () {
    $path = rawFile("From: a@example.com\r\nSubject: =?UTF-8?B?w5xuw69jw7Zkw6k=?=\r\nX-Long: first\r\n\tsecond\r\nX-Dup: 1\r\nX-Dup: 2\r\n\r\nbody\r\nSubject: not a header\r\n");

    $headers = RawHeaderBlock::read($path, 4096);

    expect($headers)->toBe([
        ['From', 'a@example.com'],
        ['Subject', 'Ünïcödé'],
        ['X-Long', 'first second'],
        ['X-Dup', '1'],
        ['X-Dup', '2'],
    ])->and(RawHeaderBlock::has($headers, 'x-dup'))->toBeTrue()
        ->and(RawHeaderBlock::first($headers, 'subject'))->toBe('Ünïcödé')
        ->and(RawHeaderBlock::all($headers, 'X-DUP'))->toBe(['1', '2'])
        ->and(RawHeaderBlock::has($headers, 'Bcc'))->toBeFalse();
    @unlink($path);
});

it('stops at the byte limit and never reads the body', function () {
    $path = rawFile('X-A: '.str_repeat('a', 100)."\r\nX-B: b\r\n\r\nbody");

    expect(RawHeaderBlock::read($path, 50))->toBe([['X-A', str_repeat('a', 45)]]);
    @unlink($path);
});

it('tolerates LF-only line endings and malformed lines', function () {
    $path = rawFile("Subject: ok\nno-colon-line\n: empty name\nX-Z: z\n\nbody");

    expect(RawHeaderBlock::read($path, 4096))->toBe([['Subject', 'ok'], ['X-Z', 'z']]);
    @unlink($path);
});
```

- [ ] **Step 2: Run tests to verify they fail** — `vendor/bin/pest tests/Unit/RawHeaderBlockTest.php` → FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

final class RawHeaderBlock
{
    /** @return list<array{0: string, 1: string}> */
    public static function read(string $rawPath, int $maxBytes): array
    {
        $handle = @fopen($rawPath, 'rb');

        if ($handle === false) {
            return [];
        }

        $headers = [];
        $current = null;
        $consumed = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $consumed += strlen($line);
                $line = rtrim($line, "\r\n");

                if ($consumed > $maxBytes) {
                    $line = substr($line, 0, max(0, strlen($line) - ($consumed - $maxBytes)));
                    self::append($headers, $current, $line);
                    break;
                }

                if ($line === '') {
                    break;
                }

                if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                    $current[1] .= ' '.trim($line);
                    continue;
                }

                self::flush($headers, $current);
                $colon = strpos($line, ':');

                if ($colon === false || $colon === 0) {
                    $current = null;
                    continue;
                }

                $current = [trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1))];
            }

            self::flush($headers, $current);
        } finally {
            fclose($handle);
        }

        return $headers;
    }

    /** @param  list<array{0: string, 1: string}>  $headers */
    private static function flush(array &$headers, ?array &$current): void
    {
        if ($current !== null) {
            $headers[] = [$current[0], self::decode($current[1])];
            $current = null;
        }
    }

    /** Handles the truncated final line. */
    private static function append(array &$headers, ?array &$current, string $line): void
    {
        if ($current !== null && ($line === '' || $line[0] === ' ' || $line[0] === "\t")) {
            $current[1] .= ' '.trim($line);
        } elseif (($colon = strpos($line, ':')) !== false && $colon > 0) {
            self::flush($headers, $current);
            $current = [trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1))];
        }

        self::flush($headers, $current);
    }

    private static function decode(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return is_string($decoded) ? $decoded : $value;
    }

    public static function has(array $headers, string $name): bool { return self::first($headers, $name) !== null; }

    public static function first(array $headers, string $name): ?string
    {
        foreach ($headers as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function all(array $headers, string $name): array
    {
        $values = [];
        foreach ($headers as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
```

- [ ] **Step 4: Run tests, Pint, PHPStan** — `vendor/bin/pest tests/Unit/RawHeaderBlockTest.php && vendor/bin/pint --dirty && vendor/bin/phpstan analyse` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: raw header block reader"`

---

### Task 5: Structured message extractor and MIME fixtures

**Files:**
- Create: `src/Mime/AddressNormalizer.php`, `src/Mime/ExtractedPart.php`, `src/Mime/ExtractedMessage.php`, `src/Mime/StructuredMessageExtractor.php`, `tests/Fixtures/Emails.php`
- Modify: `src/MailboxServiceProvider.php` (bind `StructuredMessageExtractor` singleton with `Limits`)
- Test: `tests/Unit/StructuredMessageExtractorTest.php`

**Interfaces:**
- `AddressNormalizer::normalize(list<Address> $addresses): list<array{address:string,name:string}>`; `AddressNormalizer::emails(list<Address> $addresses): list<string>`.
- `ExtractedPart` readonly: `string $id (ULID), ?string $parentId, int $position, int $depth, string $contentType, string $mediaType, string $mediaSubtype, ?string $disposition, ?string $filename, ?string $contentId, ?string $charset, ?string $transferEncoding, int $decodedBytes, ?string $sha256, bool $isInline, bool $isAttachment, ?string $blobPath`.
- `ExtractedMessage` readonly: `?string $subject, from/to/cc/bcc/replyTo (normalized lists), ?string $htmlPartId, ?string $textPartId, list<ExtractedPart> $parts, list<string> $tags, array<string,string> $metadata, ?string $previewText, ?string $searchText, string $parseStatus, ?string $parseError, int $attachmentCount, int $decodedBytes`.
- `StructuredMessageExtractor::__construct(Limits $limits)`; `extract(Email $email, string $partsDir): ExtractedMessage` — `$partsDir` must exist; blobs are written as `$partsDir/{partId}.bin`; the html/text bodies are leaf parts whose `blobPath` is set (no separate copies).
- `tests/Fixtures/Emails.php` static builders returning Symfony `Email`: `plain()`, `html()`, `alternative()`, `mixedWithAttachments()`, `relatedWithCid()`, `nestedRfc822()`, `calendar()`, `unicodeHeaders()`, `rfc2231Filename()`, `duplicateHeaders()`, `customHeaders()`, `bccOnly()`, `manyParts(int $n)`, `eightBit()`, `quotedPrintable()`, `base64Body()`.

- [ ] **Step 1: Write the fixture builders**

```php
<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Tests\Fixtures;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\MessagePart;

final class Emails
{
    public static function base(): Email
    {
        return (new Email)
            ->from(new Address('sender@example.com', 'Sender'))
            ->to(new Address('to@example.com', 'To Person'))
            ->subject('Fixture subject');
    }

    public static function plain(): Email { return self::base()->text('Plain body text'); }

    public static function html(): Email { return self::base()->html('<p>Hello <b>HTML</b></p>'); }

    public static function alternative(): Email { return self::base()->text('Text alternative')->html('<p>HTML alternative</p>'); }

    public static function mixedWithAttachments(): Email
    {
        return self::alternative()
            ->attach("%PDF-1.4 fake", 'invoice.pdf', 'application/pdf')
            ->attach("a,b\n1,2\n", 'data.csv', 'text/csv');
    }

    public static function relatedWithCid(): Email
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $email = self::base()->html('<p>Logo: <img src="cid:logo"></p>');
        $email->addPart((new DataPart($png, 'logo.png', 'image/png'))->asInline()->setContentId('logo'));

        return $email;
    }

    public static function nestedRfc822(): Email
    {
        $inner = self::plain()->subject('Inner message');
        $email = self::base()->text('Forwarded below');
        $email->addPart(new MessagePart($inner));

        return $email;
    }

    public static function calendar(): Email
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nSUMMARY:Sync\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        return self::alternative()->attach($ics, 'invite.ics', 'text/calendar');
    }

    public static function unicodeHeaders(): Email
    {
        return self::base()->from(new Address('sender@example.com', 'Sénder 日本'))->subject('Ünïcödé — 日本語 🚀')->text('Ünïcödé body');
    }

    public static function rfc2231Filename(): Email
    {
        return self::plain()->attach('x', 'résumé — 履歴書 🚀 with a very long file name that exceeds seventy six characters easily.txt', 'text/plain');
    }

    public static function duplicateHeaders(): Email
    {
        $email = self::plain();
        $email->getHeaders()->addTextHeader('X-Dup', 'one');
        $email->getHeaders()->addTextHeader('X-Dup', 'two');

        return $email;
    }

    public static function customHeaders(): Email
    {
        $email = self::plain();
        $email->getHeaders()->addTextHeader('X-Tag', 'billing');
        $email->getHeaders()->addTextHeader('X-Metadata-user_id', '42');
        $email->getHeaders()->addTextHeader('X-Custom', 'custom value');

        return $email;
    }

    public static function bccOnly(): Email
    {
        return (new Email)->from('sender@example.com')->bcc(new Address('hidden@example.com', 'Hidden'))->subject('Bcc only')->text('secret');
    }

    public static function manyParts(int $count): Email
    {
        $email = self::plain();
        for ($i = 0; $i < $count; $i++) {
            $email->attach('x'.$i, 'file'.$i.'.txt', 'text/plain');
        }

        return $email;
    }

    public static function eightBit(): Email
    {
        $email = self::base();
        $email->text('Ünïcödé 8bit', 'utf-8');
        $email->getHeaders()->addTextHeader('X-Encoding-Hint', '8bit');

        return $email;
    }

    public static function quotedPrintable(): Email
    {
        return self::base()->html('<p>'.str_repeat('Ünïcödé long line ', 30).'</p>');
    }

    public static function base64Body(): Email
    {
        return self::base()->attach(random_bytes(3000), 'blob.bin', 'application/octet-stream');
    }
}
```

- [ ] **Step 2: Write failing tests**

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Mime\StructuredMessageExtractor;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Tests\Fixtures\Emails;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/mailbox-extract-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    $this->extractor = new StructuredMessageExtractor(Limits::fromConfig([]));
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

it('extracts a plain message as a single text part', function () {
    $m = $this->extractor->extract(Emails::plain(), $this->dir);

    expect($m->parseStatus)->toBe('ok')
        ->and($m->subject)->toBe('Fixture subject')
        ->and($m->from)->toBe([['address' => 'sender@example.com', 'name' => 'Sender']])
        ->and($m->to[0]['address'])->toBe('to@example.com')
        ->and($m->parts)->toHaveCount(1)
        ->and($m->textPartId)->toBe($m->parts[0]->id)
        ->and($m->htmlPartId)->toBeNull()
        ->and(file_get_contents($m->parts[0]->blobPath))->toBe('Plain body text')
        ->and($m->previewText)->toBe('Plain body text')
        ->and($m->parts[0]->depth)->toBe(0);
});

it('extracts multipart/alternative with ordered children', function () {
    $m = $this->extractor->extract(Emails::alternative(), $this->dir);
    $types = array_map(fn ($p) => [$p->depth, $p->mediaType.'/'.$p->mediaSubtype], $m->parts);

    expect($types)->toBe([[0, 'multipart/alternative'], [1, 'text/plain'], [1, 'text/html']])
        ->and($m->parts[1]->parentId)->toBe($m->parts[0]->id)
        ->and($m->parts[0]->blobPath)->toBeNull()
        ->and($m->htmlPartId)->toBe($m->parts[2]->id)
        ->and($m->textPartId)->toBe($m->parts[1]->id);
});

it('extracts mixed attachments with filenames, dispositions and decoded blobs', function () {
    $m = $this->extractor->extract(Emails::mixedWithAttachments(), $this->dir);
    $attachments = array_values(array_filter($m->parts, fn ($p) => $p->isAttachment));

    expect($attachments)->toHaveCount(2)
        ->and($m->attachmentCount)->toBe(2)
        ->and($attachments[0]->filename)->toBe('invoice.pdf')
        ->and($attachments[0]->contentType)->toContain('application/pdf')
        ->and($attachments[0]->disposition)->toBe('attachment')
        ->and(file_get_contents($attachments[0]->blobPath))->toBe('%PDF-1.4 fake')
        ->and($attachments[0]->sha256)->toBe(hash('sha256', '%PDF-1.4 fake'))
        ->and($attachments[0]->decodedBytes)->toBe(13)
        ->and($m->parts[0]->mediaType)->toBe('multipart');
});

it('extracts related inline parts with content ids', function () {
    $m = $this->extractor->extract(Emails::relatedWithCid(), $this->dir);
    $inline = array_values(array_filter($m->parts, fn ($p) => $p->isInline));

    expect($inline)->toHaveCount(1)
        ->and($inline[0]->contentId)->toBe('logo')
        ->and($inline[0]->mediaSubtype)->toBe('png')
        ->and($inline[0]->isAttachment)->toBeFalse()
        ->and($m->attachmentCount)->toBe(0);
});

it('records nested message/rfc822 and calendar parts', function () {
    $nested = $this->extractor->extract(Emails::nestedRfc822(), $this->dir);
    $calendar = $this->extractor->extract(Emails::calendar(), $this->dir);

    expect(array_map(fn ($p) => $p->mediaType.'/'.$p->mediaSubtype, $nested->parts))->toContain('message/rfc822')
        ->and(array_map(fn ($p) => $p->mediaType.'/'.$p->mediaSubtype, $calendar->parts))->toContain('text/calendar');
});

it('keeps bcc as a separate semantic fact', function () {
    $m = $this->extractor->extract(Emails::bccOnly(), $this->dir);

    expect($m->bcc)->toBe([['address' => 'hidden@example.com', 'name' => 'Hidden']])->and($m->to)->toBe([]);
});

it('collects tags and metadata headers', function () {
    $m = $this->extractor->extract(Emails::customHeaders(), $this->dir);

    expect($m->tags)->toBe(['billing'])->and($m->metadata)->toBe(['user_id' => '42']);
});

it('decodes unicode and long filenames', function () {
    $m = $this->extractor->extract(Emails::rfc2231Filename(), $this->dir);
    $attachment = array_values(array_filter($m->parts, fn ($p) => $p->isAttachment))[0];

    expect($attachment->filename)->toStartWith('résumé — 履歴書 🚀');
    expect($this->extractor->extract(Emails::unicodeHeaders(), $this->dir)->subject)->toBe('Ünïcödé — 日本語 🚀');
});

it('marks the message partial when the part limit is exceeded but keeps what fits', function () {
    $extractor = new StructuredMessageExtractor(Limits::fromConfig(['parts' => 5]));

    $m = $extractor->extract(Emails::manyParts(10), $this->dir);

    expect($m->parseStatus)->toBe('partial')
        ->and($m->parseError)->toBe('limit:parts')
        ->and(count($m->parts))->toBeLessThanOrEqual(5);
});

it('bounds preview and search text', function () {
    $extractor = new StructuredMessageExtractor(Limits::fromConfig(['search_text_bytes' => 1024]));
    $email = Emails::base()->text(str_repeat('word ', 5000));

    $m = $extractor->extract($email, $this->dir);

    expect(strlen((string) $m->searchText))->toBeLessThanOrEqual(1024)
        ->and(mb_strlen((string) $m->previewText))->toBeLessThanOrEqual(200);
});
```

- [ ] **Step 3: Run tests to verify they fail** — `vendor/bin/pest tests/Unit/StructuredMessageExtractorTest.php` → FAIL.

- [ ] **Step 4: Implement**

`AddressNormalizer`:

```php
final class AddressNormalizer
{
    /** @param list<Address> $addresses @return list<array{address: string, name: string}> */
    public static function normalize(array $addresses): array
    {
        return array_values(array_map(fn (Address $a) => ['address' => $a->getAddress(), 'name' => $a->getName()], $addresses));
    }

    /** @param list<Address> $addresses @return list<string> */
    public static function emails(array $addresses): array
    {
        return array_values(array_map(fn (Address $a) => $a->getAddress(), $addresses));
    }
}
```

`StructuredMessageExtractor::extract()` algorithm:

1. `$parts = []; $status = 'ok'; $error = null; $htmlId = $textId = null; $decodedBytes = 0;`
2. `$root = $email->getBody();` (throws `LogicException` when the email has no body — catch: status `failed`, error `no_body`, return with empty parts).
3. Recursive `walk(AbstractPart $part, ?string $parentId, int $depth, int &$position)`:
   - if `count($parts) >= $limits->parts` → set status `partial`, error `limit:parts`, return.
   - if `$depth > $limits->depth` → status `partial`, error `limit:depth`, return.
   - `$id = (string) Str::ulid();` `$headers = $part->getPreparedHeaders();`
   - `$contentType = $headers->get('Content-Type')?->getBodyAsString() ?? $part->getMediaType().'/'.$part->getMediaSubtype()`;
   - `$disposition = $part instanceof TextPart ? $part->getDisposition() : null;` `$filename = $part instanceof DataPart ? $part->getFilename() : ($part instanceof TextPart ? $part->getName() : null);` `$contentId = $part instanceof DataPart && $part->hasContentId() ? $part->getContentId() : null;` `$charset = $headers->get('Content-Type') instanceof ParameterizedHeader ? $headers->get('Content-Type')->getParameter('charset') : null` (guard nulls); `$cte = $headers->getHeaderBody('Content-Transfer-Encoding')` (string or null).
   - `$isInline = $disposition === 'inline' && $contentId !== null;` `$isAttachment = $disposition === 'attachment' || ($disposition === 'inline' && $contentId === null && $part instanceof DataPart);`
   - if `$part instanceof AbstractMultipartPart`: push a part with `blobPath = null, decodedBytes = 0, sha256 = null`; then `foreach ($part->getParts() as $child) walk($child, $id, $depth + 1, $position)`.
   - else (leaf): `$blob = $partsDir.'/'.$id.'.bin'; $result = PartWriter::write($part, $blob);` push with sizes; `$decodedBytes += $result['bytes']`; if `text/html` and not attachment and `$htmlId === null` → `$htmlId = $id`; if `text/plain` and not attachment and `$textId === null` → `$textId = $id`.
   - Wrap the body of `walk` in try/catch(`\Throwable`): on failure set status `partial` (or `failed` if no parts yet), `error = 'part:'.get_debug_type($e)` truncated to 120 chars, and continue with the next sibling.
4. Preview/search text: read the text part blob if present (else strip tags from the html blob with `strip_tags` + `html_entity_decode`), collapse whitespace, `previewText = mb_substr(..., 0, 200)`; `searchText = strtolower(substr(implode(' ', [subject, all addresses, that text]), 0, $limits->searchTextBytes))` — ensure valid UTF-8 with `mb_convert_encoding($s, 'UTF-8', 'UTF-8')`.
5. Tags/metadata: iterate `$email->getHeaders()->all()`; `TagHeader` → `$tags[] = $header->getValue()`; `MetadataHeader` → `$metadata[$header->getKey()] = $header->getValue()`.
6. Return `new ExtractedMessage(...)` with `attachmentCount = count(filter isAttachment)`.

Never call `getBody()` on the `SentMessage`; only on the original `Email`.

- [ ] **Step 5: Run tests, Pint, PHPStan** — `vendor/bin/pest tests/Unit/StructuredMessageExtractorTest.php && vendor/bin/pint --dirty && vendor/bin/phpstan analyse` → PASS.

- [ ] **Step 6: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: structured message extractor and MIME fixtures"`

---

### Task 6: Transport, recorder, provider wiring, events

**Files:**
- Create: `src/Transport/LocalTransport.php`, `src/Transport/LocalTransportFactory.php`, `src/Capture/MessageRecorder.php`, `src/Capture/FailureInjector.php`, `src/Capture/ContextCollector.php` (minimal: namespace only — Task 7 completes it), `src/Events/MessageCaptured.php`, `src/Exceptions/CaptureFailedException.php`, `src/Storage/Pruner.php` (minimal no-op body — Task 8 completes it)
- Modify: `src/MailboxServiceProvider.php`
- Test: `tests/Feature/CaptureTest.php`

**Interfaces:**
- `LocalTransport::__construct(MessageRecorder $recorder, EnvironmentGuard $guard, ?string $mailer = null)`; `__toString(): string` returns `'local'`.
- `LocalTransportFactory::__construct(Application $app)`; `make(array $config): LocalTransport` — resolves the mailer name by finding the key in `config('mail.mailers')` whose value `=== $config`.
- `MessageRecorder::__construct(StoragePaths $paths, MessageStore $store, StructuredMessageExtractor $extractor, ContextCollector $context, Limits $limits, MaintenanceLock $lock, Pruner $pruner, Dispatcher $events, FailureInjector $failures)`; `record(SentMessage $sent, ?string $mailer): MessageRecord`.
- `FailureInjector::failAt(string $stage): void`, `reset(): void`, `check(string $stage): void`. Stage names: `before_raw_write, after_raw_write, after_extract, after_rename, before_commit, after_commit, in_event, in_prune`.
- `ContextCollector::namespace(): ?string` (from `config('mailbox.namespace')`), `take(RawMessage $original, ?string $mailer): array<string,string|null>` (Task 6: returns `['runtime' => …, 'mailer' => …, 'environment' => …, 'locale' => …]`).
- `MessageCaptured(public readonly string $id, public readonly int $seq, public readonly ?string $namespace)`.
- `Pruner::__construct(MessageStore $store, MaintenanceLock $lock, array $retention)`; `prune(bool $blocking = false): int`.

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Capture\FailureInjector;
use Rudisang\Mailbox\Events\MessageCaptured;
use Rudisang\Mailbox\Exceptions\MailboxDisabledException;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\StoragePaths;
use Rudisang\Mailbox\Tests\Fixtures\Emails;
use Symfony\Component\Mailer\Exception\TransportException;

function store(): MessageStore { return app(MessageStore::class); }
function paths(): StoragePaths { return app(StoragePaths::class); }

it('captures the exact prepared stream, envelope and original recipients', function () {
    $sent = Mail::mailer('local')->send([], [], function ($message) {
        $message->from('sender@example.com')->to('to@example.com')->cc('cc@example.com')->bcc('hidden@example.com')->subject('Capture me')->html('<p>Hi</p>')->text('Hi');
    });
    $record = store()->list()[0];
    $raw = file_get_contents(paths()->raw($record->id));

    expect($raw)->toBe($sent->getSymfonySentMessage()->toString())
        ->and($record->messageId)->toBe($sent->getSymfonySentMessage()->getMessageId())
        ->and($record->rawSha256)->toBe(hash('sha256', $raw))
        ->and($record->rawBytes)->toBe(strlen($raw))
        ->and($raw)->not->toContain('hidden@example.com')
        ->and($record->bcc[0]['address'])->toBe('hidden@example.com')
        ->and($record->envelopeRecipients)->toBe(['to@example.com', 'cc@example.com', 'hidden@example.com'])
        ->and($record->envelopeSender)->toBe('sender@example.com')
        ->and($record->rawHeaders)->toContain(['Subject', 'Capture me'])
        ->and(array_column($record->rawHeaders, 0))->not->toContain('Bcc')
        ->and($record->mailer)->toBe('local')
        ->and($record->hasHtml)->toBeTrue()->and($record->hasText)->toBeTrue()
        ->and(store()->parts($record->id))->toHaveCount(3);
});

it('captures a mailable, a notification and a queued mailable through the sync queue', function () {
    Mail::to('ada@example.com')->send(new Workbench\App\Mail\WelcomeMail('Ada'));
    Illuminate\Support\Facades\Notification::route('mail', 'n@example.com')->notify(new Workbench\App\Notifications\VerifyAccount);
    Mail::to('q@example.com')->queue(new Workbench\App\Mail\WelcomeMail('Queued'));

    expect(store()->count())->toBe(3)
        ->and(store()->list(['q' => 'Verify your email']))->toHaveCount(1)
        ->and(store()->list(['attachments' => false]))->toHaveCount(3);
});

it('emits MessageCaptured only after commit and never fails the send because of listeners', function () {
    Event::fake([MessageCaptured::class]);
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('Event')->text('x'));
    Event::assertDispatched(MessageCaptured::class, fn ($e) => $e->seq === 1 && strlen($e->id) === 26);

    Event::forgetFakes();
    app(FailureInjector::class)->failAt('in_event');
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('Event 2')->text('x'));
    expect(store()->count())->toBe(2);
});

it('throws a transport exception and leaves nothing visible when storage fails', function () {
    foreach (['before_raw_write', 'after_raw_write', 'after_extract', 'after_rename', 'before_commit'] as $stage) {
        app(FailureInjector::class)->reset();
        app(FailureInjector::class)->failAt($stage);

        expect(fn () => Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject($stage)->text('x')))
            ->toThrow(TransportException::class);
        expect(store()->count())->toBe(0, "stage {$stage} left a visible row");
        expect(glob(paths()->messagesDir().'/*') ?: [])->toBe([], "stage {$stage} left a message directory");
    }
});

it('rejects oversize messages with a transport exception', function () {
    config()->set('mailbox.limits.raw_bytes', 64 * 1024);
    $this->app->forgetInstance(Rudisang\Mailbox\Support\Limits::class);
    $this->app->forgetInstance(Rudisang\Mailbox\Capture\MessageRecorder::class);
    Mail::purge('local');

    expect(fn () => Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('big')->text(str_repeat('x', 200 * 1024))))
        ->toThrow(Rudisang\Mailbox\Exceptions\MessageTooLargeException::class);
    expect(store()->count())->toBe(0);
});

it('refuses to capture outside allowed environments', function () {
    $this->app['env'] = 'production';
    Mail::purge('local');

    expect(fn () => Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('prod')->text('x')))
        ->toThrow(MailboxDisabledException::class);
    expect(glob(paths()->messagesDir().'/*') ?: [])->toBe([]);
});

it('stores arbitrary raw messages as unsupported', function () {
    $raw = new Symfony\Component\Mime\RawMessage("From: a@example.com\r\nTo: b@example.com\r\nBcc: c@example.com\r\nSubject: raw\r\n\r\nbody");
    $transport = app(Rudisang\Mailbox\Transport\LocalTransportFactory::class)->make(['transport' => 'local']);

    $transport->send($raw, new Symfony\Component\Mailer\Envelope(new Symfony\Component\Mime\Address('a@example.com'), [new Symfony\Component\Mime\Address('b@example.com')]));
    $record = store()->list()[0];

    expect($record->parseStatus)->toBe('unsupported')
        ->and($record->rawHeaders)->toContain(['Bcc', 'c@example.com'])
        ->and(file_get_contents(paths()->raw($record->id)))->toContain('Subject: raw');
});

it('does not deduplicate identical messages', function () {
    $email = Emails::plain();
    $transport = app(Rudisang\Mailbox\Transport\LocalTransportFactory::class)->make(['transport' => 'local']);
    $transport->send($email);
    $transport->send($email);

    expect(store()->count())->toBe(2);
});
```

- [ ] **Step 2: Run tests to verify they fail** — `vendor/bin/pest tests/Feature/CaptureTest.php` → FAIL.

- [ ] **Step 3: Implement**

`src/Capture/FailureInjector.php`:

```php
final class FailureInjector
{
    /** @var array<string, true> */
    private array $stages = [];

    public function failAt(string $stage): void { $this->stages[$stage] = true; }
    public function reset(): void { $this->stages = []; }

    public function check(string $stage): void
    {
        if (isset($this->stages[$stage])) {
            unset($this->stages[$stage]);
            throw new \RuntimeException('Injected failure at '.$stage);
        }
    }
}
```

`src/Exceptions/CaptureFailedException.php`: `final class CaptureFailedException extends TransportException { public static function wrap(\Throwable $e): self { return new self('The mailbox could not store the message: '.$e->getMessage(), 0, $e); } }`

`src/Events/MessageCaptured.php`: readonly constructor promotion of `id, seq, namespace`.

`src/Transport/LocalTransport.php`:

```php
final class LocalTransport extends AbstractTransport
{
    public function __construct(private readonly MessageRecorder $recorder, private readonly EnvironmentGuard $guard, private readonly ?string $mailer = null)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->guard->assertAllowed();
        $this->recorder->record($message, $this->mailer);
    }

    public function __toString(): string
    {
        return 'local';
    }
}
```

`src/Transport/LocalTransportFactory.php`:

```php
final class LocalTransportFactory
{
    public function __construct(private readonly Application $app) {}

    /** @param array<string, mixed> $config */
    public function make(array $config): LocalTransport
    {
        $mailer = null;
        foreach ((array) $this->app->make('config')->get('mail.mailers', []) as $name => $candidate) {
            if ($candidate === $config) {
                $mailer = (string) $name;
                break;
            }
        }

        return new LocalTransport($this->app->make(MessageRecorder::class), $this->app->make(EnvironmentGuard::class), $mailer);
    }
}
```

`src/Capture/MessageRecorder::record()`:

```php
public function record(SentMessage $sent, ?string $mailer): MessageRecord
{
    $id = (string) Str::ulid();
    $tmp = $this->paths->tmp($id);
    $final = $this->paths->message($id);

    try {
        $this->paths->ensureRoot();
        if (! @mkdir($tmp.DIRECTORY_SEPARATOR.'parts', 0755, true) && ! is_dir($tmp.DIRECTORY_SEPARATOR.'parts')) {
            throw new RuntimeException('Unable to create the capture staging directory.');
        }

        $this->failures->check('before_raw_write');
        $raw = RawStreamWriter::write($sent->toIterable(), $tmp.DIRECTORY_SEPARATOR.'raw.eml', $this->limits->rawBytes);
        $this->failures->check('after_raw_write');

        $original = $sent->getOriginalMessage();
        $envelope = $sent->getEnvelope();
        $rawHeaders = RawHeaderBlock::read($tmp.DIRECTORY_SEPARATOR.'raw.eml', $this->limits->headerBytes);

        $extracted = $original instanceof Email
            ? $this->extractor->extract($original, $tmp.DIRECTORY_SEPARATOR.'parts')
            : ExtractedMessage::unsupported();
        $this->failures->check('after_extract');

        $context = $this->context->take($original, $mailer);
        $record = $this->buildRecord($id, $sent, $envelope, $raw, $rawHeaders, $extracted, $mailer, $context);
        $parts = $this->buildParts($id, $extracted);

        $seq = $this->lock->shared(function () use ($tmp, $final, $record, $parts): int {
            if (! @rename($tmp, $final)) {
                throw new RuntimeException('Unable to move the capture into place.');
            }
            $this->failures->check('after_rename');
            try {
                $this->failures->check('before_commit');

                return $this->store->insert($record, $parts);
            } catch (\Throwable $e) {
                MessageStore::removeDirectory($final);
                throw $e;
            }
        });
    } catch (TransportException $e) {
        MessageStore::removeDirectory($tmp);
        throw $e;
    } catch (\Throwable $e) {
        MessageStore::removeDirectory($tmp);
        throw CaptureFailedException::wrap($e);
    }

    $record = $record->withSeq($seq);   // add `withSeq(int): self` to MessageRecord (clone with seq)

    try {
        $this->failures->check('after_commit');
        $this->events->dispatch(new MessageCaptured($id, $seq, $record->namespace));
        $this->failures->check('in_event');
    } catch (\Throwable) {
        // Listeners can never invalidate a committed capture.
    }

    try {
        $this->pruner->prune(false);
    } catch (\Throwable) {
        // Pruning is best-effort after capture.
    }

    return $record;
}
```

`buildRecord()` maps: `capturedAt = gmdate('Y-m-d\TH:i:s\Z')`; `messageId = $sent->getMessageId()` inside try/catch (`RawMessage` has none → `null`); `envelopeSender = $envelope->getSender()->getAddress()`; `envelopeRecipients = AddressNormalizer::emails($envelope->getRecipients())`; `subject` from extracted or `RawHeaderBlock::first($rawHeaders, 'Subject')` for unsupported; `previewText/searchText/hasHtml/hasText/partCount/attachmentCount/decodedBytes` from extracted; `namespace = $this->context->namespace()`; `context = $context`. `ExtractedMessage::unsupported()` returns a status `unsupported` instance with empty lists. `buildParts()` converts `ExtractedPart` → `PartRecord` (drop `blobPath`; blobs are addressed by `StoragePaths::part()`).

Provider additions (`boot()`):

```php
$this->app->make('mail.manager')->extend('local', function (array $config = []) {
    return $this->app->make(LocalTransportFactory::class)->make($config);
});
```

(Use `Illuminate\Support\Facades\Mail::extend` only if `mail.manager` is not resolvable; both exist in L12/13.) Register singletons for `FailureInjector`, `ContextCollector`, `Pruner`, `StructuredMessageExtractor`, `MessageRecorder`, `LocalTransportFactory` in `register()`.

`ContextCollector` (Task 6 minimal):

```php
final class ContextCollector
{
    public function __construct(private readonly Application $app, private readonly Repository $config) {}

    public function namespace(): ?string
    {
        $ns = $this->config->get('mailbox.namespace');

        return is_string($ns) && $ns !== '' ? substr($ns, 0, 128) : null;
    }

    /** @return array<string, string|null> */
    public function take(RawMessage $original, ?string $mailer): array
    {
        return [
            'runtime' => $this->app->runningInConsole() ? 'console' : 'http',
            'mailer' => $mailer,
            'environment' => (string) $this->app->environment(),
            'locale' => (string) $this->app->getLocale(),
        ];
    }
}
```

`Pruner::prune()` (Task 6 minimal): `return 0;` — Task 8 fills it in.

- [ ] **Step 4: Run tests, Pint, PHPStan** — `vendor/bin/pest tests/Feature/CaptureTest.php && vendor/bin/pint --dirty && vendor/bin/phpstan analyse` → PASS. Also run the whole suite: `vendor/bin/pest`.

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: local transport and durable message recorder"`

---

### Task 7: Provenance and namespace propagation

**Files:**
- Modify: `src/Capture/ContextCollector.php`, `src/MailboxServiceProvider.php`
- Create: `src/Mailbox.php`
- Test: `tests/Feature/ProvenanceTest.php`

**Interfaces:**
- `ContextCollector::rememberSending(Email $message, array $data): void`, `forgetSending(): void`, `jobStarted(string $connection, JobContract $job): void`, `jobFinished(): void`, `add(array $scalars): void`, `redactUsing(?callable $fn): void`, `take(...)` now returns keys: `runtime (http|console|queue), mailer, environment, locale, request_method, request_path, command, mailable, notification, notification_id, job, job_id, queue, connection` plus app-supplied keys (prefixed `app.`), all `string|null`, bounded (≤ 64 keys, ≤ 1024 bytes each).
- `Mailbox::context(array $scalars): void`, `Mailbox::redactContextUsing(?callable $fn): void`.
- Provider (when guard allows): listens `MessageSending` → `rememberSending`; `MessageSent` → `forgetSending`; `JobProcessing` → `jobStarted` **and** sets `config('mailbox.namespace')` from `$job->payload()['mailbox_namespace']` when present (remembering the previous value); `JobProcessed`/`JobFailed`/`JobExceptionOccurred` → `jobFinished` and restore the previous namespace; `Queue::createPayloadUsing(fn () => ['mailbox_namespace' => config('mailbox.namespace')])` (only adds the key when the namespace is non-null).

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Rudisang\Mailbox\Mailbox;
use Rudisang\Mailbox\Storage\MessageStore;
use Workbench\App\Mail\WelcomeMail;

it('records the mailable and notification class best-effort', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    Illuminate\Support\Facades\Notification::route('mail', 'n@example.com')->notify(new Workbench\App\Notifications\VerifyAccount);
    $list = app(MessageStore::class)->list();

    expect($list[1]->context['mailable'])->toBe(WelcomeMail::class)
        ->and($list[0]->context['notification'])->toBe(Workbench\App\Notifications\VerifyAccount::class)
        ->and($list[0]->context['notification_id'])->not->toBeNull()
        ->and($list[1]->context['runtime'])->toBe('console')
        ->and($list[1]->context['environment'])->toBe('testing');
});

it('records queue job context and takes the namespace from the job payload', function () {
    config()->set('mailbox.namespace', 'outer');
    $job = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn(['uuid' => 'job-uuid-1', 'displayName' => 'App\\Jobs\\SendMail', 'mailbox_namespace' => 'from-payload']);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendMail');
    $job->shouldReceive('uuid')->andReturn('job-uuid-1');
    $job->shouldReceive('getQueue')->andReturn('emails');

    event(new JobProcessing('database', $job));
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('in job')->text('x'));
    event(new JobProcessed('database', $job));
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('after job')->text('x'));
    $list = app(MessageStore::class)->list();

    expect($list[1]->namespace)->toBe('from-payload')
        ->and($list[1]->context['job'])->toBe('App\\Jobs\\SendMail')
        ->and($list[1]->context['job_id'])->toBe('job-uuid-1')
        ->and($list[1]->context['queue'])->toBe('emails')
        ->and($list[1]->context['runtime'])->toBe('queue')
        ->and($list[0]->namespace)->toBe('outer')
        ->and($list[0]->context['job'])->toBeNull();
});

it('adds the current namespace to queued job payloads', function () {
    config()->set('mailbox.namespace', 'ns-42');
    config()->set('queue.default', 'sync');
    $payload = null;
    Queue::before(function (JobProcessing $event) use (&$payload) { $payload = $event->job->payload(); });

    Mail::to('q@example.com')->queue(new WelcomeMail('Queued'));

    expect($payload['mailbox_namespace'] ?? null)->toBe('ns-42');
});

it('accepts bounded app context and applies redaction', function () {
    Mailbox::redactContextUsing(fn (array $ctx) => array_merge($ctx, ['app.secret' => '[redacted]']));
    Mailbox::context(['secret' => 'hunter2', 'order' => 77, 'huge' => str_repeat('x', 5000), 'obj' => new stdClass]);

    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('ctx')->text('x'));
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('ctx2')->text('x'));
    $list = app(MessageStore::class)->list();

    expect($list[1]->context['app.secret'])->toBe('[redacted]')
        ->and($list[1]->context['app.order'])->toBe('77')
        ->and(strlen($list[1]->context['app.huge']))->toBe(1024)
        ->and(array_key_exists('app.obj', $list[1]->context))->toBeFalse()
        ->and(array_key_exists('app.order', $list[0]->context))->toBeFalse();
    Mailbox::redactContextUsing(null);
});

it('does not misattribute a mailable when the send is cancelled', function () {
    Mail::before(fn () => false); // cancels the next send via MessageSending listener
    Mail::to('ada@example.com')->send(new WelcomeMail('Cancelled'));
    expect(app(MessageStore::class)->count())->toBe(0);

    app('events')->forget(Illuminate\Mail\Events\MessageSending::class);
    (new Rudisang\Mailbox\MailboxServiceProvider($this->app))->boot();
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('plain send')->text('x'));

    expect(app(MessageStore::class)->list()[0]->context['mailable'])->toBeNull();
});
```

If `Mail::before` does not exist in the installed Laravel, replace the first line with `Event::listen(MessageSending::class, fn () => false);`.

- [ ] **Step 2: Run tests to verify they fail** — `vendor/bin/pest tests/Feature/ProvenanceTest.php` → FAIL.

- [ ] **Step 3: Implement**

`ContextCollector` full version:

```php
final class ContextCollector
{
    private const MAX_KEYS = 64;
    private const MAX_VALUE = 1024;

    /** @var array{subject: ?string, to: list<string>, data: array<string, mixed>}|null */
    private ?array $sending = null;
    /** @var array<string, string|null> */
    private array $job = [];
    /** @var array<string, string> */
    private array $app = [];
    /** @var (callable(array<string, string|null>): array<string, string|null>)|null */
    private $redactor = null;

    public function __construct(private readonly Application $app, private readonly Repository $config) {}

    public function namespace(): ?string { /* as Task 6 */ }

    /** @param array<string, mixed> $data */
    public function rememberSending(Email $message, array $data): void
    {
        $this->sending = ['subject' => $message->getSubject(), 'to' => AddressNormalizer::emails($message->getTo()), 'data' => $data];
    }

    public function forgetSending(): void { $this->sending = null; }

    public function jobStarted(string $connection, JobContract $job): void
    {
        $payload = $job->payload();
        $this->job = [
            'job' => $job->resolveName(),
            'job_id' => $job->uuid() ?? (isset($payload['uuid']) ? (string) $payload['uuid'] : null),
            'queue' => $job->getQueue(),
            'connection' => $connection,
        ];
    }

    public function jobFinished(): void { $this->job = []; }

    /** @param array<string, mixed> $scalars */
    public function add(array $scalars): void
    {
        foreach ($scalars as $key => $value) {
            if (! is_scalar($value) && $value !== null) { continue; }
            $this->app['app.'.substr((string) $key, 0, 64)] = substr((string) $value, 0, self::MAX_VALUE);
            if (count($this->app) >= self::MAX_KEYS) { break; }
        }
    }

    public function redactUsing(?callable $fn): void { $this->redactor = $fn; }

    /** @return array<string, string|null> */
    public function take(RawMessage $original, ?string $mailer): array
    {
        $context = [
            'runtime' => $this->job !== [] ? 'queue' : ($this->app->runningInConsole() ? 'console' : 'http'),
            'mailer' => $mailer,
            'environment' => (string) $this->app->environment(),
            'locale' => (string) $this->app->getLocale(),
            'request_method' => null, 'request_path' => null, 'command' => null,
            'mailable' => null, 'notification' => null, 'notification_id' => null,
            'job' => null, 'job_id' => null, 'queue' => null, 'connection' => null,
        ];

        if (! $this->app->runningInConsole() && $this->app->bound('request')) {
            $request = $this->app->make('request');
            $context['request_method'] = $request->getMethod();
            $context['request_path'] = '/'.ltrim($request->path(), '/');
        } elseif (isset($_SERVER['argv'][1]) && is_string($_SERVER['argv'][1])) {
            $context['command'] = substr($_SERVER['argv'][1], 0, 128);
        }

        if ($this->sending !== null && $original instanceof Email
            && $this->sending['subject'] === $original->getSubject()
            && $this->sending['to'] === AddressNormalizer::emails($original->getTo())) {
            $data = $this->sending['data'];
            $context['mailable'] = isset($data['__laravel_mailable']) && is_string($data['__laravel_mailable']) ? $data['__laravel_mailable'] : null;
            $context['notification'] = isset($data['__laravel_notification']) && is_string($data['__laravel_notification']) ? $data['__laravel_notification'] : null;
            $context['notification_id'] = isset($data['__laravel_notification_id']) ? (string) $data['__laravel_notification_id'] : null;
        }
        $this->sending = null;

        $context = array_merge($context, $this->job, $this->app);
        $this->app = [];

        if ($this->redactor !== null) {
            $context = ($this->redactor)($context);
        }

        return $this->bound($context);
    }

    /** @param array<mixed, mixed> $context @return array<string, string|null> */
    private function bound(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (count($out) >= self::MAX_KEYS) { break; }
            if ($value !== null && ! is_scalar($value)) { continue; }
            $out[substr((string) $key, 0, 64)] = $value === null ? null : substr((string) $value, 0, self::MAX_VALUE);
        }

        return $out;
    }
}
```

`src/Mailbox.php`:

```php
final class Mailbox
{
    /** @param array<string, mixed> $scalars */
    public static function context(array $scalars): void { app(ContextCollector::class)->add($scalars); }

    public static function redactContextUsing(?callable $fn): void { app(ContextCollector::class)->redactUsing($fn); }
}
```

Provider `boot()` additions, guarded by `if ($this->app->make(EnvironmentGuard::class)->allows())`:

```php
$events = $this->app->make('events');
$events->listen(MessageSending::class, fn (MessageSending $e) => $this->app->make(ContextCollector::class)->rememberSending($e->message, $e->data));
$events->listen(MessageSent::class, fn () => $this->app->make(ContextCollector::class)->forgetSending());
$events->listen(JobProcessing::class, function (JobProcessing $e): void {
    $collector = $this->app->make(ContextCollector::class);
    $collector->jobStarted((string) $e->connectionName, $e->job);
    $payload = $e->job->payload();
    if (isset($payload['mailbox_namespace']) && is_string($payload['mailbox_namespace'])) {
        $config = $this->app->make('config');
        $this->previousNamespace = $config->get('mailbox.namespace');
        $config->set('mailbox.namespace', $payload['mailbox_namespace']);
        $this->namespaceOverridden = true;
    }
});
foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
    $events->listen($event, function (): void {
        $this->app->make(ContextCollector::class)->jobFinished();
        if ($this->namespaceOverridden) {
            $this->app->make('config')->set('mailbox.namespace', $this->previousNamespace);
            $this->namespaceOverridden = false;
        }
    });
}
Queue::createPayloadUsing(function () {
    $ns = $this->app->make('config')->get('mailbox.namespace');

    return is_string($ns) && $ns !== '' ? ['mailbox_namespace' => $ns] : [];
});
```

(`Queue` here is `Illuminate\Queue\Queue`, the abstract base class exposing the static hook.) Keep `$previousNamespace`/`$namespaceOverridden` as private provider properties.

- [ ] **Step 4: Run tests, Pint, PHPStan** — `vendor/bin/pest tests/Feature/ProvenanceTest.php && vendor/bin/pest && vendor/bin/pint --dirty && vendor/bin/phpstan analyse` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: scalar provenance and queue namespace propagation"`

---

### Task 8: Pruner, repair, console commands

**Files:**
- Create: `src/Storage/Repair.php`, `src/Console/DoctorCommand.php`, `src/Console/ClearCommand.php`, `src/Console/PruneCommand.php`
- Modify: `src/Storage/Pruner.php`, `src/MailboxServiceProvider.php` (register commands inside `runningInConsole()`)
- Test: `tests/Feature/RetentionTest.php`, `tests/Feature/ConsoleTest.php`

**Interfaces:**
- `Pruner::prune(bool $blocking = false): int` — under `exclusive($fn, $blocking)`; removes ids from `idsOlderThan(now - days)`, then `idsBeyondCount(max_messages)`, then `idsBeyondBytes(max_bytes)`; returns removed count; returns 0 when the lock is busy.
- `Repair::__construct(StoragePaths $paths, MessageStore $store, MaintenanceLock $lock)`; `scan(): array{orphan_dirs: list<string>, dangling_rows: list<string>, stale_tmp: list<string>}`; `repair(): array{orphan_dirs:int, dangling_rows:int, stale_tmp:int}` (exclusive blocking lock; stale tmp = mtime older than 600 s).
- Commands: `mailbox:doctor {--repair} {--json}` exit 1 on critical; `mailbox:clear {--force}` (asks `confirm` unless `--force` or non-interactive); `mailbox:prune`.

- [ ] **Step 1: Write failing tests**

`tests/Feature/RetentionTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Pruner;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\StoragePaths;

function sendOne(string $subject): void
{
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject($subject)->text('x'));
}

it('prunes by count with one-message slack and removes directories', function () {
    config()->set('mailbox.retention.max_messages', 3);
    $this->app->forgetInstance(Pruner::class);
    $this->app->forgetInstance(Rudisang\Mailbox\Capture\MessageRecorder::class);
    Mail::purge('local');

    foreach (range(1, 6) as $i) {
        sendOne("m{$i}");
    }
    $store = app(MessageStore::class);

    expect($store->count())->toBeLessThanOrEqual(4)
        ->and($store->list()[0]->subject)->toBe('m6')
        ->and(count(glob(app(StoragePaths::class)->messagesDir().'/*') ?: []))->toBe($store->count());
});

it('prunes by age', function () {
    sendOne('old');
    $store = app(MessageStore::class);
    $store->pdo()->exec("UPDATE messages SET captured_at = '2020-01-01T00:00:00Z'");
    sendOne('new');

    expect(array_map(fn ($m) => $m->subject, $store->list()))->toBe(['new']);
});

it('prunes by total bytes', function () {
    config()->set('mailbox.retention.max_bytes', 2000);
    $this->app->forgetInstance(Pruner::class);
    $this->app->forgetInstance(Rudisang\Mailbox\Capture\MessageRecorder::class);
    Mail::purge('local');

    foreach (range(1, 8) as $i) {
        sendOne('bytes'.$i);
    }

    expect(app(MessageStore::class)->totals()['bytes'])->toBeLessThan(2000 + 1500);
});

it('scans and repairs orphan directories, dangling rows and stale tmp', function () {
    sendOne('keep');
    $paths = app(StoragePaths::class);
    $store = app(MessageStore::class);
    $keep = $store->list()[0]->id;
    mkdir($paths->message('01ORPHAN00000000000000000A'), 0755, true);
    mkdir($paths->tmp('01STALE000000000000000000A'), 0755, true);
    touch($paths->tmp('01STALE000000000000000000A'), time() - 3600);
    mkdir($paths->tmp('01FRESH000000000000000000A'), 0755, true);
    sendOne('dangling');
    $dangling = $store->list()[0]->id;
    MessageStore::removeDirectory($paths->message($dangling));

    $repair = app(Repair::class);
    $scan = $repair->scan();
    expect($scan['orphan_dirs'])->toBe(['01ORPHAN00000000000000000A'])
        ->and($scan['dangling_rows'])->toBe([$dangling])
        ->and($scan['stale_tmp'])->toBe(['01STALE000000000000000000A']);

    expect($repair->repair())->toBe(['orphan_dirs' => 1, 'dangling_rows' => 1, 'stale_tmp' => 1])
        ->and($store->find($keep))->not->toBeNull()
        ->and($store->find($dangling))->toBeNull()
        ->and(is_dir($paths->tmp('01FRESH000000000000000000A')))->toBeTrue()
        ->and($repair->scan())->toBe(['orphan_dirs' => [], 'dangling_rows' => [], 'stale_tmp' => []]);
});
```

`tests/Feature/ConsoleTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;

it('doctor reports healthy state and exits 0', function () {
    $this->artisan('mailbox:doctor')->expectsOutputToContain('Environment')->assertExitCode(0);
});

it('doctor reports a mailer collision, failover composition and production as critical', function () {
    config()->set('mail.mailers.local', ['transport' => 'smtp']);
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['smtp', 'local']]);

    $this->artisan('mailbox:doctor')->expectsOutputToContain('mail.mailers.local')->expectsOutputToContain('failover')->assertExitCode(1);

    $this->app['env'] = 'production';
    $this->artisan('mailbox:doctor', ['--json' => true])->assertExitCode(1);
});

it('doctor --repair removes orphans', function () {
    mkdir(app(Rudisang\Mailbox\Support\StoragePaths::class)->message('01ORPHAN00000000000000000B'), 0755, true);

    $this->artisan('mailbox:doctor', ['--repair' => true])->expectsOutputToContain('orphan')->assertExitCode(0);
    expect(is_dir(app(Rudisang\Mailbox\Support\StoragePaths::class)->message('01ORPHAN00000000000000000B')))->toBeFalse();
});

it('clear and prune commands work', function () {
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('x')->text('x'));

    $this->artisan('mailbox:prune')->assertExitCode(0);
    $this->artisan('mailbox:clear', ['--force' => true])->assertExitCode(0);
    expect(app(Rudisang\Mailbox\Storage\MessageStore::class)->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail** — `vendor/bin/pest tests/Feature/RetentionTest.php tests/Feature/ConsoleTest.php` → FAIL.

- [ ] **Step 3: Implement**

`Pruner::prune()`:

```php
public function prune(bool $blocking = false): int
{
    $result = $this->lock->exclusive(function (): int {
        $removed = [];
        $days = max(0, (int) ($this->retention['days'] ?? 7));
        if ($days > 0) {
            $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$days} days")->format('Y-m-d\TH:i:s\Z');
            $removed = array_merge($removed, $this->store->idsOlderThan($cutoff));
        }
        $removed = array_merge($removed, $this->store->idsBeyondCount(max(1, (int) ($this->retention['max_messages'] ?? 1000))));
        $removed = array_merge($removed, $this->store->idsBeyondBytes(max(1024 * 1024, (int) ($this->retention['max_bytes'] ?? 250 * 1024 * 1024))));
        foreach (array_unique($removed) as $id) {
            $this->store->delete($id);
        }

        return count(array_unique($removed));
    }, $blocking);

    return is_int($result) ? $result : 0;
}
```

Because `idsBeyondCount`/`idsBeyondBytes` are computed before deletions, run them sequentially: delete age victims first, then recompute count victims, then bytes victims (three small loops), so the totals are accurate.

`Repair`: `scan()` lists `messagesDir()` entries not in `allIds()` (orphan_dirs), ids from `allIds()` whose `raw()` is missing (dangling_rows), `tmpDir()` entries with `filemtime < time() - 600` (stale_tmp); `repair()` under `exclusive()` blocking removes each and returns counts.

`DoctorCommand` findings (each `['level' => 'ok|warning|critical', 'label' => …, 'detail' => …]`):
- Environment: `critical` when `!guard->allows()` (detail = reason), else `ok`.
- Mailer: `critical` when `config('mail.mailers.local.transport') !== 'local'`; `warning` when `config('mail.default') !== 'local'`.
- Failover: `critical` when any `mail.mailers.*.mailers` array contains `'local'`.
- Storage: `critical` when root cannot be created or is not writable; `ok` with path otherwise.
- SQLite: `ok` with `sqlite_version()`; `warning` when version `< 3.35`.
- Caches: `warning` when `config:cache` is active (`$this->laravel->configurationIsCached()`) — note that env changes require `config:cache` again; `ok` otherwise. Same for routes (`routesAreCached()`).
- Retention: totals vs limits (`warning` when > 90 %).
- Orphans: from `Repair::scan()` (`warning` when any; after `--repair` print counts).
Output as a table (or JSON with `--json`); exit code 1 when any `critical`.

Register in the provider inside `runningInConsole()`: `$this->commands([DoctorCommand::class, ClearCommand::class, PruneCommand::class]);`

- [ ] **Step 4: Run tests, Pint, PHPStan** — `vendor/bin/pest && vendor/bin/pint --dirty && vendor/bin/phpstan analyse` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: retention pruning, repair and doctor/clear/prune commands"`

---

### Task 9: Concurrency and crash suites

**Files:**
- Create: `tests/Concurrency/ConcurrentCaptureTest.php`, `tests/Concurrency/worker.php`, `tests/Feature/CrashConsistencyTest.php`

**Interfaces:** consumes everything above; no new production code expected (fix bugs found).

- [ ] **Step 1: Write the worker script** (`tests/Concurrency/worker.php`)

```php
<?php

declare(strict_types=1);

// Usage: php worker.php <storage-root> <count> <namespace>
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Orchestra\Testbench\Foundation\Application;

$app = Application::create(basePath: null, options: ['extra' => ['providers' => [Rudisang\Mailbox\MailboxServiceProvider::class]], 'env' => ['APP_ENV' => 'testing']]);
$app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
$app['config']->set('mailbox.storage_path', $argv[1]);
$app['config']->set('mailbox.namespace', $argv[3]);
$app['config']->set('mail.default', 'local');

$mailer = $app->make('mail.manager')->mailer('local');
$pruner = $app->make(Rudisang\Mailbox\Storage\Pruner::class);
$repair = $app->make(Rudisang\Mailbox\Storage\Repair::class);
$count = (int) $argv[2];

for ($i = 0; $i < $count; $i++) {
    $mailer->send([], [], fn ($m) => $m->from('w@example.com')->to('t@example.com')->subject("w {$argv[3]} {$i}")->text(str_repeat('x', 2000)));
    if ($i % 10 === 0) {
        $pruner->prune(false);
        $repair->repair();
    }
}
echo 'done';
```

If `Application::create` named arguments are unsupported in the installed Testbench, use `Orchestra\Testbench\Foundation\Application::create(null, ['extra' => [...], 'env' => [...]])` positionally.

- [ ] **Step 2: Write the concurrency test**

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\StoragePaths;

it('captures from eight processes concurrently without lost or torn records', function () {
    $root = app(StoragePaths::class)->root;
    $procs = $pipes = [];
    for ($p = 0; $p < 8; $p++) {
        $procs[$p] = proc_open([PHP_BINARY, __DIR__.'/worker.php', $root, '100', 'proc-'.$p], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$p]);
    }
    $outputs = [];
    foreach ($procs as $p => $proc) {
        $outputs[$p] = stream_get_contents($pipes[$p][1]).' '.stream_get_contents($pipes[$p][2]);
        proc_close($proc);
    }
    $store = app(MessageStore::class);

    expect(array_map('trim', $outputs))->each->toBe('done');
    expect($store->count())->toBe(800);
    foreach ($store->allIds() as $id) {
        expect(app(StoragePaths::class)->raw($id))->toBeFile();
    }
    expect(app(Repair::class)->scan())->toBe(['orphan_dirs' => [], 'dangling_rows' => [], 'stale_tmp' => []]);
    for ($p = 0; $p < 8; $p++) {
        expect($store->count(['namespace' => 'proc-'.$p]))->toBe(100);
    }
})->group('concurrency');
```

Set the default retention high enough for this test in `TestCase::defineEnvironment` (`mailbox.retention.max_messages = 5000`, `max_bytes = 1 GiB`).

- [ ] **Step 3: Write the crash consistency test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Capture\FailureInjector;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\StoragePaths;

it('leaves either a complete visible capture or a repairable invisible orphan at every stage', function () {
    $stages = ['before_raw_write', 'after_raw_write', 'after_extract', 'after_rename', 'before_commit', 'after_commit', 'in_event', 'in_prune'];
    $store = app(MessageStore::class);
    $paths = app(StoragePaths::class);

    foreach ($stages as $stage) {
        app(FailureInjector::class)->reset();
        app(FailureInjector::class)->failAt($stage);
        $before = $store->count();
        try {
            Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject($stage)->text('x'));
            $threw = false;
        } catch (\Throwable) {
            $threw = true;
        }
        $after = $store->count();
        $committedStages = ['after_commit', 'in_event', 'in_prune'];

        expect($threw)->toBe(! in_array($stage, $committedStages, true), "stage {$stage}");
        expect($after - $before)->toBe(in_array($stage, $committedStages, true) ? 1 : 0, "stage {$stage}");
        foreach ($store->allIds() as $id) {
            expect($paths->raw($id))->toBeFile();
        }
        expect(app(Repair::class)->scan()['orphan_dirs'])->toBe([], "stage {$stage} left an orphan dir");
    }
});
```

- [ ] **Step 4: Run and fix** — `vendor/bin/pest tests/Concurrency tests/Feature/CrashConsistencyTest.php` → PASS. If concurrency fails with lock errors, tune `MessageStore::retry` (never remove the test). Then `vendor/bin/pest && vendor/bin/pint --dirty && vendor/bin/phpstan analyse`.

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "test: concurrency and crash consistency suites"`

---

## Self-review (done by the plan author)

- Spec coverage: §3 (Task 1, 6, 7), §4 (Task 1), §5 (Tasks 2, 4, 5, 6), §6 (Task 3, 8), §7 (Task 1), §8 (Task 7), §13 (Task 8), §14 unit/feature/concurrency/crash (Tasks 2–9). Routes/UI (§9–11) and testing API (§12) are Phase 2 and Phase 3.
- Types: `MessageRecord::fromRow/toRow/withSeq/isRead`, `PartRecord::fromRow/toRow/isLeaf`, `MessageStore` method names, `StoragePaths` method names, `FailureInjector` stage names, `ContextCollector` keys are used consistently across tasks.
