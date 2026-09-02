# Phase 3 — Testing API, Cross-Process Isolation, Docs and Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Public testing contracts (`InteractsWithMailbox`, `mailbox()`, `CapturedMessage`) with final-output assertions, namespace isolation that survives real queue workers, field-aware snapshots, JSON/JUnit diagnostics artifacts, and release-grade documentation and CI.

**Architecture:** `MailboxTester` queries `MessageStore` scoped to a per-test namespace and a `seq` high-water mark; `CapturedMessage` wraps a `MessageRecord` with lazy blob access and PHPUnit assertions labelled by fact source (raw headers / original structured / envelope). The trait snapshots and restores configuration and purges the `local` mailer. `Queue::createPayloadUsing` (Phase 1) carries the namespace into workers.

**Tech Stack:** PHPUnit assertions via Pest, Laravel queue (`database` driver) subprocess, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-28-laravel-mailbox-design.md` §12–§15 and product spec §13, §15–§16.

## Global Constraints

- All Phase 1 global constraints apply.
- Public API surface is exactly: `config/mailbox.php`, the three commands, `Rudisang\Mailbox\Events\MessageCaptured`, `Rudisang\Mailbox\Mailbox`, `Rudisang\Mailbox\Testing\{InteractsWithMailbox, MailboxTester, CapturedMessage}` and the global `mailbox()` helper. Everything else is `@internal`.
- Assertion failure messages must say which fact source they used (e.g. "raw header", "original message", "envelope").

---

## File map (Phase 3)

| File | Responsibility |
|---|---|
| `src/Testing/InteractsWithMailbox.php` | Trait lifecycle + `mailbox()` |
| `src/Testing/MailboxTester.php` | Queries, waiting, collection assertions |
| `src/Testing/CapturedMessage.php` | Facts + assertions + exports |
| `src/Testing/Snapshots.php` | Field-aware normalisers |
| `src/Testing/DiagnosticsWriter.php` | JSON / JUnit serialisation |
| `src/Testing/helpers.php` | `mailbox()` |
| `tests/Feature/Testing/*` | Suite |
| `README.md`, `UPGRADE.md`, `.github/SECURITY.md`, `CHANGELOG.md`, `.github/workflows/tests.yml` | Release |

---

### Task 14: Tester, captured message, trait, helper

**Files:**
- Create: `src/Testing/MailboxTester.php`, `src/Testing/CapturedMessage.php`, `src/Testing/InteractsWithMailbox.php`; modify `src/Testing/helpers.php`
- Modify: `src/MailboxServiceProvider.php` (no change unless a binding is needed)
- Test: `tests/Feature/Testing/AssertionsTest.php`, `tests/Feature/Testing/TraitTest.php`

**Interfaces:**
- `MailboxTester::__construct(MessageStore $store, StoragePaths $paths, HtmlPreviewSanitizer $sanitizer, AttachmentPolicy $policy, ?string $namespace, int $highWater)`; `namespace(): ?string`; `anyNamespace(): self`; `all(): list<CapturedMessage>` (newest first, namespace-scoped); `latest(): CapturedMessage` (fails "No mailbox captures in namespace X"); `count(): int`; `find(string $id): ?CapturedMessage`; `whereMessageId(string $id): list<CapturedMessage>`; `whereTo(string $address): list`; `whereSubject(string $subject): list` (exact, case-insensitive); `whereTag(string $tag): list`; `waitForCapture(int $count = 1, float $timeout = 5.0): list<CapturedMessage>` (polls `countSince(highWater, namespace)` every 50 ms until ≥ count or timeout → fails with "Timed out after Ns waiting for N capture(s)"); returns the new messages and advances the high-water mark; `assertCaptured(int $count): self`; `assertNothingCaptured(): self`; `clear(): void` (deletes namespace-scoped messages).
- `CapturedMessage::__construct(MessageRecord $record, MessageStore $store, StoragePaths $paths, HtmlPreviewSanitizer $sanitizer, AttachmentPolicy $policy)`; accessors: `id(), seq(), messageId(), subject(), from(), to(), cc(), bcc(), replyTo()` (lists of `['address','name']`), `envelopeSender(), envelopeRecipients()`, `rawHeaders(): list<array{0:string,1:string}>`, `header(string $name): ?string`, `headers(string $name): list<string>`, `html(): ?string`, `text(): ?string`, `raw(): string`, `parts(): list<PartRecord>`, `attachments(): list<PartRecord>`, `attachmentContent(string $filename): ?string`, `tags(), metadata(), context(), parseStatus(), parseError()`, `diagnostics(): array`, `links(): list`, `record(): MessageRecord`.
- Assertions (all return `static`): `assertFrom($address)`, `assertTo($address)`, `assertCc`, `assertBcc`, `assertReplyTo`, `assertSubject(string $exact)`, `assertSubjectContains`, `assertHtmlContains`, `assertHtmlNotContains`, `assertTextContains`, `assertSeeInHtml(string $text)` (tag-stripped + entity-decoded), `assertHasAttachment(string $filename, ?string $mime = null)`, `assertAttachmentCount(int)`, `assertHasInlineImage(string $contentId)`, `assertHeader(string $name, ?string $value = null)` (raw), `assertRawHeaderMissing(string $name)`, `assertRawContains(string $needle)`, `assertEnvelopeContains(string $address)`, `assertEnvelopeSender(string $address)`, `assertTag(string)`, `assertMetadata(string $key, string $value)`, `assertNoParseErrors()`, `assertNoRemoteImages()`, `assertNoScripts()`.
- Trait: `setUpInteractsWithMailbox()`, `tearDownInteractsWithMailbox()`, `mailbox(): MailboxTester`. It binds the tester with `app()->instance(MailboxTester::class, $tester)`; `mailbox()` helper returns `app(MailboxTester::class)` and throws a clear `LogicException` when the trait is not in use.

- [ ] **Step 1: Write failing tests**

`tests/Feature/Testing/AssertionsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Workbench\App\Mail\HostileMail;
use Workbench\App\Mail\InvoiceMail;
use Workbench\App\Mail\WelcomeMail;

uses(InteractsWithMailbox::class);

it('asserts on the final invoice email', function () {
    Mail::to('buyer@example.com')->send(new InvoiceMail(123));

    $this->mailbox()->latest()
        ->assertFrom('billing@acme.test')
        ->assertTo('buyer@example.com')
        ->assertCc('accounts@example.com')
        ->assertBcc('audit@example.com')
        ->assertReplyTo('support@acme.test')
        ->assertSubject('Your invoice #123')
        ->assertSubjectContains('invoice')
        ->assertHtmlContains('Invoice #123')
        ->assertSeeInHtml('Amount due')
        ->assertTextContains('Amount due')
        ->assertHasAttachment('invoice-123.pdf', 'application/pdf')
        ->assertHasAttachment('line-items.csv')
        ->assertAttachmentCount(2)
        ->assertHeader('Subject', 'Your invoice #123')
        ->assertHeader('X-Tag', 'billing')
        ->assertRawHeaderMissing('Bcc')
        ->assertRawContains('Content-Type: multipart/mixed')
        ->assertEnvelopeSender('billing@acme.test')
        ->assertEnvelopeContains('audit@example.com')
        ->assertTag('billing')
        ->assertNoParseErrors()
        ->assertNoRemoteImages()
        ->assertNoScripts();
});

it('exposes facts and inline images', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    $m = mailbox()->latest();

    $m->assertHasInlineImage(array_values(array_filter($m->parts(), fn ($p) => $p->isInline))[0]->contentId)
        ->assertMetadata('user_id', '42');
    expect($m->html())->toContain('Welcome aboard')
        ->and($m->text())->toContain('Welcome aboard')
        ->and($m->raw())->toContain('Subject: Welcome')
        ->and($m->messageId())->not->toBeNull()
        ->and($m->context()['mailable'])->toBe(WelcomeMail::class)
        ->and($m->links()[0]['url'])->toContain('acme.test/onboarding')
        ->and($m->attachmentContent('missing.pdf'))->toBeNull();
});

it('fails with fact-source labelled messages', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));

    expect(fn () => mailbox()->latest()->assertTo('nobody@example.com'))->toThrow(AssertionFailedError::class, 'original message');
    expect(fn () => mailbox()->latest()->assertHeader('X-Nope'))->toThrow(AssertionFailedError::class, 'raw header');
    expect(fn () => mailbox()->latest()->assertEnvelopeContains('nobody@example.com'))->toThrow(AssertionFailedError::class, 'envelope');
    expect(fn () => mailbox()->latest()->assertHtmlContains('nope'))->toThrow(AssertionFailedError::class);
});

it('flags hostile mail through diagnostics assertions', function () {
    Mail::to('v@example.com')->send(new HostileMail);

    expect(fn () => mailbox()->latest()->assertNoScripts())->toThrow(AssertionFailedError::class);
    expect(fn () => mailbox()->latest()->assertNoRemoteImages())->toThrow(AssertionFailedError::class);
    expect(mailbox()->latest()->diagnostics()['results'])->not->toBe([]);
});

it('queries by recipient, subject, tag and message id', function () {
    Mail::to('a@example.com')->send(new InvoiceMail(1));
    Mail::to('b@example.com')->send(new WelcomeMail('B'));
    $tester = mailbox();

    expect($tester->count())->toBe(2)
        ->and($tester->whereTo('a@example.com'))->toHaveCount(1)
        ->and($tester->whereSubject('your invoice #1'))->toHaveCount(1)
        ->and($tester->whereTag('onboarding'))->toHaveCount(1)
        ->and($tester->whereMessageId($tester->latest()->messageId()))->toHaveCount(1)
        ->and($tester->find($tester->latest()->id())?->id())->toBe($tester->latest()->id())
        ->and($tester->assertCaptured(2))->toBe($tester);
    $tester->clear();
    $tester->assertNothingCaptured();
});
```

`tests/Feature/Testing/TraitTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Rudisang\Mailbox\Testing\MailboxTester;
use Workbench\App\Mail\WelcomeMail;

uses(InteractsWithMailbox::class);

it('scopes each test to its own namespace and only sees its own captures', function () {
    config()->set('mailbox.namespace', 'someone-else');
    app(MessageStore::class); // same store
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject('foreign')->text('x'));
    config()->set('mailbox.namespace', $this->mailbox()->namespace());
    Mail::to('me@example.com')->send(new WelcomeMail('Me'));

    expect($this->mailbox()->count())->toBe(1)
        ->and($this->mailbox()->anyNamespace()->count())->toBe(2)
        ->and($this->mailbox()->namespace())->toHaveLength(26);
});

it('selects the local mailer and restores configuration afterwards', function () {
    expect(config('mail.default'))->toBe('local')
        ->and(mailbox())->toBeInstanceOf(MailboxTester::class)
        ->and(mailbox())->toBe($this->mailbox());
    $this->tearDownInteractsWithMailbox();
    expect(config('mailbox.namespace'))->toBeNull();
    $this->setUpInteractsWithMailbox();
});

it('honours MAILBOX_NAMESPACE from the environment', function () {
    $this->tearDownInteractsWithMailbox();
    config()->set('mailbox.namespace', 'from-env');
    $this->setUpInteractsWithMailbox();

    expect($this->mailbox()->namespace())->toBe('from-env');
});

it('waits for captures with a bounded timeout', function () {
    $start = microtime(true);
    expect(fn () => $this->mailbox()->waitForCapture(1, 0.3))->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Timed out');
    expect(microtime(true) - $start)->toBeLessThan(1.5);

    Mail::to('me@example.com')->send(new WelcomeMail('Me'));
    $new = $this->mailbox()->waitForCapture(1, 1.0);
    expect($new)->toHaveCount(1)->and($new[0]->subject())->toContain('Welcome');
});
```

- [ ] **Step 2: Run tests to verify they fail** — `vendor/bin/pest tests/Feature/Testing` → FAIL.

- [ ] **Step 3: Implement**

Trait:

```php
trait InteractsWithMailbox
{
    /** @var array<string, mixed> */
    private array $mailboxConfigSnapshot = [];

    protected function setUpInteractsWithMailbox(): void
    {
        $config = $this->app['config'];
        $this->mailboxConfigSnapshot = ['mail.default' => $config->get('mail.default'), 'mailbox.namespace' => $config->get('mailbox.namespace')];

        $namespace = $config->get('mailbox.namespace');
        $namespace = is_string($namespace) && $namespace !== '' ? $namespace : (string) Str::ulid();
        $config->set('mailbox.namespace', $namespace);
        $config->set('mail.default', 'local');
        $this->app->make('mail.manager')->purge('local');
        $this->app->forgetInstance(MessageRecorder::class);

        $store = $this->app->make(MessageStore::class);
        $tester = new MailboxTester($store, $this->app->make(StoragePaths::class), $this->app->make(HtmlPreviewSanitizer::class), $this->app->make(AttachmentPolicy::class), $namespace, $store->status($namespace)['seq']);
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
```

Note: Laravel's base `TestCase::setUpTraits()` automatically calls `setUp{TraitName}` and registers `tearDown{TraitName}` — do not call them manually from `setUp()`.

`helpers.php`:

```php
if (! function_exists('mailbox')) {
    function mailbox(): \Rudisang\Mailbox\Testing\MailboxTester
    {
        $app = \Illuminate\Container\Container::getInstance();
        if (! $app->bound(\Rudisang\Mailbox\Testing\MailboxTester::class)) {
            throw new \LogicException('mailbox() requires the Rudisang\Mailbox\Testing\InteractsWithMailbox trait on the test case.');
        }

        return $app->make(\Rudisang\Mailbox\Testing\MailboxTester::class);
    }
}
```

`CapturedMessage` assertions use `PHPUnit\Framework\Assert::assertTrue($condition, $message)`; messages: e.g. `assertTo`: "Expected [{$address}] among the To recipients of the original message; got [a, b]."; `assertHeader`: "Expected raw header [X] to be present" / "... to equal [v]; got [w]"; `assertEnvelopeContains`: "Expected [x] among the envelope recipients; got [...]". `html()` reads the html leaf blob via `MessagePresenter`-like logic (reuse `MessagePresenter::html()` if it is `@internal` but public). `assertNoScripts()` uses `diagnostics()`: fail if `html.scripts_removed` or `html.event_handlers` or `html.javascript_urls` present. `assertNoRemoteImages()` fails if `html.remote_images` present.

- [ ] **Step 4: Run tests, Pint, PHPStan** — `vendor/bin/pest && vendor/bin/pint --dirty && vendor/bin/phpstan analyse` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: testing api with final-output assertions and namespaces"`

---

### Task 15: Snapshots, diagnostics artifacts, exports

**Files:**
- Create: `src/Testing/Snapshots.php`, `src/Testing/DiagnosticsWriter.php`
- Modify: `src/Testing/CapturedMessage.php`
- Test: `tests/Feature/Testing/SnapshotsTest.php`

**Interfaces:**
- `CapturedMessage::htmlSnapshot(): string` (cid refs → `cid:part-{n}` in document order; otherwise verbatim), `textSnapshot(): string` (verbatim), `headersSnapshot(): string` (`Name: value` lines; `Message-ID` → `<message-id>`, `Date` → `<date>`), `mimeTreeSnapshot(): string` (indented lines `multipart/alternative`, `  text/plain (12 B)`, `  image/png inline cid=logo (95 B) logo.png`), `assertMatchesSnapshot(string $expectedHtml)` (compares `htmlSnapshot()`).
- `saveEml(string $path): string` (copies raw unchanged; returns path), `saveDiagnostics(string $path, string $format = 'json'): string` (`json` pretty; `junit` XML `<testsuite name="mailbox:{id}">` with one `<testcase name="{rule}">` per result, `<failure>` for error, `<system-out>` for warning/info, plus a passing `<testcase name="no-findings">` when empty), `saveFixture(string $path): string` (JSON: subject, from/to/cc/reply-to, raw headers, html, text, attachments metadata, parse status — **no bcc, no context, no envelope recipients beyond To/Cc**).

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Workbench\App\Mail\InvoiceMail;
use Workbench\App\Mail\WelcomeMail;

uses(InteractsWithMailbox::class);

beforeEach(fn () => $this->dir = sys_get_temp_dir().'/mailbox-snap-'.bin2hex(random_bytes(4)));
afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

it('produces stable field-aware snapshots', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    $first = mailbox()->latest();
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    $second = mailbox()->latest();

    expect($first->htmlSnapshot())->toBe($second->htmlSnapshot())->toContain('cid:part-1')->not->toContain('cid:'.$first->parts()[0]->contentId)
        ->and($first->headersSnapshot())->toBe($second->headersSnapshot())->toContain('Message-ID: <message-id>')->toContain('Date: <date>')
        ->and($first->mimeTreeSnapshot())->toBe($second->mimeTreeSnapshot())->toContain('multipart/')->toContain('image/png inline')
        ->and($first->textSnapshot())->toBe($first->text());
    $first->assertMatchesSnapshot($second->htmlSnapshot());
});

it('writes eml, json and junit artifacts and a redacted fixture', function () {
    mkdir($this->dir, 0755, true);
    Mail::to('buyer@example.com')->send(new InvoiceMail(5));
    $m = mailbox()->latest();

    expect(file_get_contents($m->saveEml($this->dir.'/m.eml')))->toBe($m->raw());
    $json = json_decode(file_get_contents($m->saveDiagnostics($this->dir.'/d.json')), true);
    expect($json['rules_version'])->toBe(Rudisang\Mailbox\Security\Diagnostics::RULES_VERSION)->and($json['capture_id'])->toBe($m->id());
    $xml = simplexml_load_file($m->saveDiagnostics($this->dir.'/d.xml', 'junit'));
    expect((string) $xml['name'])->toBe('mailbox:'.$m->id())->and($xml->testcase)->not->toBeEmpty();
    $fixture = json_decode(file_get_contents($m->saveFixture($this->dir.'/f.json')), true);
    expect($fixture['subject'])->toBe('Your invoice #5')
        ->and($fixture)->not->toHaveKey('bcc')->not->toHaveKey('context')
        ->and(json_encode($fixture))->not->toContain('audit@example.com');
});
```

- [ ] **Step 2: Run tests to verify they fail** — `vendor/bin/pest tests/Feature/Testing/SnapshotsTest.php` → FAIL.

- [ ] **Step 3: Implement** `Snapshots` (static helpers taking the `CapturedMessage`/records) and `DiagnosticsWriter` (`json(array $report): string`, `junit(array $report): string` using `XMLWriter`); wire methods on `CapturedMessage`.

- [ ] **Step 4: Run tests, Pint, PHPStan** — PASS.

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: snapshots, diagnostics artifacts and exports"`

---

### Task 16: Cross-process worker isolation and cache tests

**Files:**
- Create: `tests/Feature/Testing/CrossProcessTest.php`, `tests/Feature/CachingTest.php`

- [ ] **Step 1: Cross-process test** — dispatch a queued mailable with `queue.default = database` against a file-based SQLite database (`database.connections.sqlite.database = {tmp}/queue.sqlite`, create the `jobs` table with Laravel's default schema via `Schema::create`), then run `php vendor/bin/testbench queue:work database --once --stop-when-empty` via `proc_open` with env `DB_CONNECTION=sqlite DB_DATABASE={tmp}/queue.sqlite MAILBOX_STORAGE_PATH={storage} MAIL_MAILER=local APP_ENV=testing QUEUE_CONNECTION=database APP_KEY=...`; then `mailbox()->waitForCapture(1, 10.0)` and assert `namespace === mailbox()->namespace()` and `context['job']` set. Skip with a clear reason when `proc_open` is disabled. Expect the Testbench CLI to boot the package via discovery (`composer prepare` already ran).

- [ ] **Step 2: Cache test** — `tests/Feature/CachingTest.php`: run `$this->artisan('config:cache')` then boot a fresh app? Testbench cannot reboot easily; instead assert: `config:cache` succeeds with the package config (no closures) and `route:cache` succeeds (`$this->artisan('route:cache')->assertSuccessful()`) and both caches are cleared in `afterEach` (`config:clear`, `route:clear`). Also assert every mailbox route uses controller `[class, method]` actions (no closures) by iterating `Route::getRoutes()` and checking `$route->getActionName() !== 'Closure'`.

- [ ] **Step 3: Run, fix, commit** — `vendor/bin/pest` → PASS; `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "test: cross-process worker isolation and cache compatibility"`

---

### Task 17: Documentation, CI, release readiness

**Files:**
- Create: `README.md`, `UPGRADE.md`, `.github/workflows/tests.yml` (replace), `.github/workflows/update-changelog.yml` (from skeleton)
- Modify: `.github/SECURITY.md`, `CHANGELOG.md`, `composer.json` (description/keywords final), `.gitattributes`

- [ ] **Step 1: README** sections: hero + promise (and the explicit non-promise: no Gmail/Outlook emulation, no production use); Requirements (PHP 8.2+, Laravel 12/13); Install (`composer require --dev rudisang/laravel-mailbox`, `MAIL_MAILER=local`, open `/_mailbox`); What gets captured (raw, envelope, original Bcc, parts); The UI (tabs, viewports, theme, shortcuts table); Security model (environments, sandbox, CSP, attachments, links, CSS containment, captures contain secrets — clear/prune); Testing (`InteractsWithMailbox`, `mailbox()`, assertion table with fact sources, `waitForCapture`, namespaces with real workers, snapshots, diagnostics JSON/JUnit, `saveEml`); Queues (payload propagation; `MAILBOX_NAMESPACE`; at-least-once duplicates are kept); Configuration reference; Commands (`doctor`, `clear`, `prune`); Storage layout + retention; Troubleshooting (`doctor`, config cache, failover mailers); Comparison note vs Mailpit/Mailtrap Local (complementary; export `.eml`); Contributing; License.

- [ ] **Step 2: SECURITY.md** — supported versions, report channel (GitHub private advisory), threat model summary (from spec §10), what is *not* covered (CSS containment, production use).

- [ ] **Step 3: CI workflow** — the §15 matrix (L12 floor 8.2/lowest, L12 latest 8.4, L13 floor 8.3/lowest, L13 latest 8.5, Windows 8.4, browser job, quality job with `composer validate --strict`, `composer audit`, Pint, Larastan, type coverage). Use `composer require "laravel/framework:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update` then `composer update --${{ matrix.stability }} --prefer-dist --no-interaction`. Exclude the `concurrency` group on Windows if `proc_open` behaves differently (`--exclude-group concurrency`).

- [ ] **Step 4: CHANGELOG** — `## v0.1.0` entry listing capture kernel, mailbox UI, testing API, diagnostics.

- [ ] **Step 5: Final gates** — `composer validate --strict && composer audit && vendor/bin/pint --test && vendor/bin/phpstan analyse && vendor/bin/pest && vendor/bin/pest --type-coverage --min=95` all pass; `gzip -c resources/dist/mailbox.css resources/dist/mailbox.js | wc -c` ≤ 256000.

- [ ] **Step 6: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "docs: readme, security policy, changelog and ci matrix"`

---

## Self-review

- Spec coverage: §12 (Tasks 14–16), §13 handled in Phase 1, §15 CI (Task 17), product spec §13 differentiators: final-MIME assertions (14), cross-process isolation (16), JSON/JUnit diagnostics (15).
- Consistency: `MailboxTester`/`CapturedMessage` method names match the README table to be written in Task 17; `Diagnostics::RULES_VERSION`, `MessageStore::status/countSince`, `StoragePaths::raw` reused from Phase 1/2.
