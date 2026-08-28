# Laravel Mailbox — implementation design

Status: approved for planning (approval delegated to the orchestrating agent by the package owner on 2026-08-28)
Parent document: the "Laravel Local Mail Workbench" product specification (28 Aug 2026). This document records every decision that specification left open, and the concrete shape of v1. Where the two disagree, this document wins because it is later and narrower.

## 1. Identity

| Item | Decision |
|---|---|
| Composer name | `rudisang/laravel-mailbox` (owner's GitHub login is `rudisang`; directory is `laravel-mailbox`) |
| PHP namespace | `Rudisang\Mailbox` |
| Config file / key | `config/mailbox.php` → `mailbox.*` |
| Mail transport name | `local` (parent-spec hard rule: `MAIL_MAILER=local` must work with no `config/mail.php` edit) |
| Route prefix | `/_mailbox` (config `mailbox.path`) |
| Artisan commands | `mailbox:install`, `mailbox:doctor`, `mailbox:url`, `mailbox:clear`, `mailbox:prune` |
| Env vars | `MAILBOX_ENABLED`, `MAILBOX_PATH`, `MAILBOX_STORAGE_PATH`, `MAILBOX_NAMESPACE` |
| Testing entry points | trait `Rudisang\Mailbox\Testing\InteractsWithMailbox`, global helper `mailbox()`, class `CapturedMessage` |
| Storage | `storage/framework/mailbox/` (private, gitignored by Laravel already) |
| License | MIT |

The product name in UI and docs is **Mailbox**. The transport stays `local` because it describes what the mailer does from the application's point of view.

## 2. Platform and dependencies

- PHP `^8.2`; Laravel `^12.0 || ^13.0`; Symfony mailer/mime `^7.2 || ^8.0`; Testbench `^10 || ^11`; Pest `^4 || ^5`; Larastan `^3`; Pint.
- Runtime `require`: `php`, `ext-dom`, `ext-json`, `ext-mbstring`, `ext-pdo`, `ext-pdo_sqlite`, `ext-fileinfo`, `illuminate/console`, `illuminate/contracts`, `illuminate/events`, `illuminate/filesystem`, `illuminate/http`, `illuminate/mail`, `illuminate/routing`, `illuminate/support`, `illuminate/view`, `symfony/mailer`, `symfony/mime`, `symfony/html-sanitizer`, `symfony/uid` (ULID; already a Laravel dependency).
- No Node, no build step, no npm dependencies in the package repository. CSS and JS are hand-authored and committed under `resources/dist/`.
- Layout, `composer.json` scripts, Pint config, Larastan config, arch tests, CI matrix, `.gitattributes`, `SECURITY.md`, `release.yml` and `dependabot.yml` mirror the official `laravel/package-skeleton`.

## 3. Service provider contract

```
register():  mergeConfigFrom(mailbox); bind EnvironmentGuard, StoragePaths, MessageStore (SqliteMessageStore),
             BlobStore, MessageRecorder, ContextCollector, HtmlPreviewSanitizer, AttachmentPolicy;
             add mail.mailers.local = ['transport' => 'local'] only if the app has not defined it.
boot():      Mail::extend('local', ...); listen MessageSending + JobProcessing (provenance only, scalar);
             if guard allows: loadRoutesFrom(routes/web.php), loadViewsFrom('mailbox');
             if runningInConsole(): commands([...]), publishes(config => 'mailbox-config'), AboutCommand::add.
```

- No filesystem, PDO, or network work happens in `register()` or `boot()`. The SQLite connection opens lazily on first store use.
- Nested default merge for `mail.mailers.local` is explicit (`config()->set` only when `config('mail.mailers.local')` is null). The collision case (app defines `mail.mailers.local` with a different transport) is a `mailbox:doctor` finding.
- Routes are loaded only when `EnvironmentGuard::allows()`; the `Authorize` middleware re-checks at runtime so cached routes cannot leak into a disallowed environment.
- The transport constructor calls `EnvironmentGuard::assertAllowed()`; outside allowed environments a `Rudisang\Mailbox\Exceptions\MailboxDisabledException` (extends Symfony `TransportException`) is thrown before any message is accepted.

## 4. Environment guard

```php
'enabled'      => env('MAILBOX_ENABLED'),                 // null = auto by environment
'environments' => ['local', 'testing'],
```

`allows()` = `enabled === true` || (`enabled === null` && `app()->environment(environments)`). `APP_DEBUG` is never consulted. `enabled === true` in a non-listed environment is honoured only if `mailbox.middleware` includes something other than `web` **or** a `viewMailbox` gate is defined; otherwise `doctor` reports a critical finding and `allows()` returns false. Production (`app()->environment('production')`) is refused unconditionally in v1.

## 5. Capture pipeline (LocalTransport → MessageRecorder)

1. `LocalTransport::doSend(SentMessage $sent)` delegates to `MessageRecorder::record($sent, $mailerName)`.
2. Allocate ULID `$id`; create `tmp/{id}/` under the storage root.
3. Stream `$sent->toIterable()` to `tmp/{id}/raw.eml` through a bounded writer that counts bytes and hashes SHA-256; abort with `MessageTooLargeException` (a `TransportException`) once `limits.raw_bytes` is exceeded.
4. `$original = $sent->getOriginalMessage()`; `$envelope = $sent->getEnvelope()`.
5. If `$original instanceof Symfony\Component\Mime\Email`: `StructuredMessageExtractor` walks `$original->getHeaders()`, `getHtmlBody()`, `getTextBody()`, `getAttachments()` and builds a `NormalizedMessage` (headers, addresses, bodies, parts) under the configured limits. Each attachment/inline body is streamed to `tmp/{id}/parts/{partUlid}.bin` via `DataPart::bodyToIterable()`; decoded size and SHA-256 are computed while streaming. If `$original` is any other `RawMessage`, only raw + envelope are stored and `parse_status = unsupported`.
6. Extraction exceptions are caught and recorded as `parse_status = partial|failed` with a stable `parse_error` code; the raw file is never discarded.
7. `ContextCollector::take()` returns scalar provenance (see §8) and the current test namespace.
8. `fsync`-style flush (`fflush` + `fclose`), then `rename(tmp/{id}, messages/{id})` (atomic on one volume).
9. `SqliteMessageStore::insert()` inserts message + parts + FTS rows in one transaction (busy timeout 5 s).
10. On insert failure the `messages/{id}` directory is removed and the exception is rethrown as `TransportException`.
11. After commit: dispatch `Rudisang\Mailbox\Events\MessageCaptured($id, $namespace)`, then opportunistic `Pruner::prune()` wrapped in try/catch so it can never fail the send. Listener exceptions are caught and logged (capture id only).

`raw.eml` is authoritative for content; the SQLite row is authoritative for listing. Nothing is visible until step 9 commits.

## 6. Storage

```
storage/framework/mailbox/
├── index.sqlite
├── messages/{ulid}/raw.eml
├── messages/{ulid}/parts/{partUlid}.bin
└── tmp/{ulid}/...
```

SQLite via PDO directly (not a Laravel DB connection, to avoid polluting `config('database.connections')` and to control pragmas). Pragmas: `busy_timeout=5000`, `foreign_keys=ON`, `synchronous=NORMAL`, `journal_mode` from config (`auto` ⇒ `wal` when `sqlite_version() >= 3.51.3`, else `delete`). FTS5 is feature-detected at schema creation; the fallback is an indexed `LIKE` over `search_text`.

Tables (schema v1): `mailbox_meta(key, value)`, `messages`, `parts`, optional `messages_fts` (external-content FTS5 over subject, addresses, search_text). `SchemaMigrator` applies numbered internal migrations under an exclusive transaction; it is idempotent and safe to run concurrently.

`messages` columns: `id, schema_version, captured_at, message_id, raw_sha256, raw_bytes, mailer, parse_status, parse_error, subject, from_json, to_json, cc_json, bcc_json, reply_to_json, envelope_sender, envelope_recipients_json, tags_json, metadata_json, has_html, has_text, preview_text, search_text, part_count, attachment_count, decoded_bytes, read_at, namespace, context_json`.

`parts` columns: `id, message_id, parent_id, position, content_type, media_family, disposition, filename, content_id, charset, transfer_encoding, encoded_bytes, decoded_bytes, sha256, blob_path, is_inline, preview_eligible`.

Retention: `Pruner` enforces `retention.days`, `retention.max_messages`, `retention.max_bytes` (oldest first) after each capture and via `mailbox:prune`. Orphan directories (no row) and `tmp/` entries older than 10 minutes are removed by `mailbox:doctor --repair`.

## 7. Limits (config `mailbox.limits`)

`raw_bytes` 50 MiB, `parts` 100, `depth` 30, `headers_per_part` 200, `header_bytes` 256 KiB, `search_text_bytes` 512 KiB. Values are clamped to safe ranges on read.

## 8. Provenance (`ContextCollector`)

Scalar facts only, all optional and marked `null` when unknown:

- `runtime`: `http|console|queue|testing`
- `mailer`: name passed to the transport factory
- `environment`, `locale`
- `mailable`: from `MessageSending::$data['__laravel_mailable']` when present
- `notification`, `notification_id`: from `__laravel_notification*` data keys
- `job`, `job_id`, `queue`, `connection`: from the last `JobProcessing` event in this process (cleared on `JobProcessed`/`JobFailed`)
- `request_method`, `request_path`: from the current request (path only, never query string)
- `command`: `$_SERVER['argv'][1]` when running in console
- `tags`, `metadata`: Symfony `TagHeader` / `MetadataHeader` values from the original message

A `MessageSending` listener stores `mailable/notification` facts keyed by the Symfony message object; `MessageRecorder` consumes them for the same object in the same synchronous send. Applications may add scalars through `Mailbox::context(['key' => 'value'])` (cleared after the next capture) and may register a redaction callback `Mailbox::redactContextUsing(fn (array $ctx): array)`.

## 9. HTTP surface

All routes under `mailbox.path`, middleware `mailbox.middleware` (default `['web']`) plus `Rudisang\Mailbox\Http\Middleware\Authorize`.

```
GET     /                                  inbox (full page; ?partial=list returns the list fragment)
GET     /messages/{id}                     detail (full page; ?partial=detail returns the detail fragment)
GET     /messages/{id}/preview/html        sanitized HTML document for the sandboxed iframe
GET     /messages/{id}/preview/text        text/plain body, escaped, in a minimal document
GET     /messages/{id}/raw                 raw.eml (inline text/plain; ?download=1 → .eml attachment)
GET     /messages/{id}/parts/{part}        attachment download (octet-stream) or allowlisted inline image
GET     /messages/{id}/cid/{cid}           inline image by Content-ID (allowlisted raster only)
POST    /messages/{id}/read                toggle read state (JSON body {read: bool})
DELETE  /messages/{id}
POST    /clear
GET     /api/status?since={ulid}           {latest, total, unread}  (ETag; used by the poller)
GET     /api/messages?q=&filter=&page=     JSON list
GET     /api/messages/{id}                 JSON detail
GET     /assets/{hash}/{file}              immutable package assets from resources/dist
```

The JSON shape, HTML fragments and DOM are internal and unversioned.

## 10. Preview security (HtmlPreviewSanitizer)

Pipeline (runs at response time, no cache):

1. Parse the HTML with `DOMDocument` (libxml, entity loading disabled, size-capped).
2. Collect `<style>` element text; scrub CSS by removing `@import` rules, `expression(`, `behavior:`, `-moz-binding`, and every `url(...)` whose scheme is not `data:`; count removals for diagnostics.
3. Rewrite `src`/`background` URLs: `cid:X` → `{path}/messages/{id}/cid/{X}`; remote `http(s)` → 1×1 transparent `data:` placeholder plus `data-mailbox-remote="{url}"`, preserving `width/height/alt`; count remote images and detect tracking pixels (1×1 or hidden).
4. Rewrite every `href` to `#` and store the original in `data-mailbox-href`; collect links for the Links tab. Forms, scripts, iframes, objects, embeds, `<base>`, `<meta http-equiv>` are removed by step 5 and counted.
5. Serialize the body and run Symfony `HtmlSanitizer` with: safe elements, `style` attribute allowed on all elements, `data-mailbox-*` attributes allowed, media schemes `['data']` + relative, link schemes none (links are already `#`), `allowRelativeMedias()`, max input length = `limits.preview_bytes`.
6. Emit a package-owned document: `<!doctype html><html><head><meta charset><meta name=viewport><style>{scrubbed css}</style></head><body>{sanitized}</body></html>`.

Response headers: `Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'self' data:; connect-src 'none'; form-action 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'; sandbox; frame-ancestors 'self'`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`. The workbench page embeds it with `<iframe sandbox src=...>` (empty sandbox token list) and sends its own CSP (`default-src 'self'; frame-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'`).

Text preview: escaped text in `<pre>` inside the same hardened document shape.

Inline images: `cid` and `parts` routes serve only parts whose sniffed (`finfo`) type is in `image/png, image/jpeg, image/gif, image/webp, image/bmp`; anything else on the `cid` route is 404 and on the `parts` route is a forced download. Downloads use `application/octet-stream`, `Content-Disposition: attachment` built with Symfony `HeaderUtils::makeDisposition` (ASCII fallback, CR/LF/control characters stripped, 120-char cap), `Content-Length`, `nosniff`.

## 11. Mailbox UI

Server-rendered Blade plus one ES module (`mailbox.js`) and one stylesheet (`mailbox.css`); both are committed, content-hashed at request time (`md5_file`, cached per process) and served with `Cache-Control: public, max-age=31536000, immutable`. No CDN, no fonts fetched, no host publish step.

Screens: inbox (two-pane ≥ 960 px, single pane below), detail (deep-linkable), empty state, 404 for unknown message. Detail tabs: HTML, Text, Headers, Envelope, MIME, Raw, Attachments, Links, Diagnostics. Toolbar: viewport presets (Phone 375 / Tablet 768 / Desktop 100 %), theme toggle (system/light/dark, persisted in `localStorage`), download `.eml`, mark unread, delete. Global: search (debounced, server-side), filters (unread, attachments, parse errors, namespace), unread badge, clear-all with confirm, keyboard shortcuts (`j/k` move, `Enter` open, `/` search, `e` delete, `u` unread, `[`/`]` tabs, `?` help), live region for "N new messages".

Progressive enhancement: every action is a plain link or form and works with JavaScript off; JavaScript upgrades navigation to fragment swaps (`?partial=`), history via `pushState`, polling every 2 s while visible (30 s when hidden, paused after 10 min idle), and shortcuts.

### Visual direction (brief for the design agent)

- Monochrome. Light: canvas `#FFFFFF`, surface `#FAFAFA`, ink `#0A0A0A`, muted `#6B6B6B`, hairline `#E6E6E6`. Dark is the exact inverse: canvas `#0A0A0A`, surface `#141414`, ink `#FAFAFA`, muted `#9A9A9A`, hairline `#262626`. One semantic amber for warnings and one red for destructive actions, used sparingly.
- Typography: system UI stack (`-apple-system, BlinkMacSystemFont, "Segoe UI", Inter, Roboto, sans-serif`), tight tracking on headings, tabular numerals for times/sizes; `ui-monospace` for headers/raw/MIME.
- Shape: 16 px radius cards, 999 px pill buttons and chips, 10 px inputs; hairline borders instead of shadows in light mode, soft elevation in dark mode.
- Motion: 160 ms ease-out on hover/focus/tab changes, list rows slide in on arrival, detail pane crossfades; everything respects `prefers-reduced-motion`.
- Feel: calm, dense but breathable, "Linear/Vercel-grade" polish. Nothing looks like Bootstrap or a default admin template.
- Accessibility: visible 2 px focus rings, roles/labels on lists and tabs, WCAG 2.2 AA contrast, full keyboard operation.
- Budget: ≤ 250 KiB compressed for CSS + JS combined; realistic target ≤ 40 KiB.

## 12. Testing API

```php
uses(InteractsWithMailbox::class);

it('sends the final invoice email', function () {
    Mail::to('buyer@example.com')->cc('accounts@example.com')->send(new InvoiceMail($invoice));

    mailbox()->latest()
        ->assertTo('buyer@example.com')
        ->assertCc('accounts@example.com')
        ->assertSubject('Your invoice')
        ->assertHtmlContains('Invoice #123')
        ->assertTextContains('Amount due')
        ->assertHasAttachment('invoice-123.pdf', 'application/pdf')
        ->assertRawHeaderMissing('Bcc')
        ->assertEnvelopeContains('audit@example.com')
        ->assertNoParseErrors();
});
```

- The trait, in `setUp`, sets `mail.default = local`, points `mailbox.storage_path` at a per-process temp directory unless `MAILBOX_STORAGE_PATH` is set, and sets `mailbox.namespace` to `MAILBOX_NAMESPACE` env or a fresh ULID per test. Nothing global is mutated permanently.
- `mailbox()` returns `MailboxTester`: `latest()`, `all()`, `count()`, `find($id)`, `whereMessageId()`, `whereTo()`, `whereSubject()`, `whereTag()`, `anyNamespace()`, `waitForCapture(int $count = 1, float $timeout = 5.0)` (polls the store every 50 ms; throws on timeout), `assertNothingCaptured()`, `assertCaptured(int $count)`.
- `CapturedMessage` exposes facts (`id, messageId, subject, from, to, cc, bcc, replyTo, envelopeSender, envelopeRecipients, html, text, raw, headers, parts, attachments, tags, metadata, context, parseStatus, diagnostics`) and assertions (`assertFrom, assertTo, assertCc, assertBcc, assertReplyTo, assertSubject, assertSubjectContains, assertHtmlContains, assertHtmlNotContains, assertTextContains, assertSeeInHtml (tag-stripped), assertHasAttachment, assertAttachmentCount, assertHasInlineImage, assertHeader, assertRawHeaderMissing, assertRawContains, assertEnvelopeContains, assertEnvelopeSender, assertTag, assertMetadata, assertNoParseErrors, assertNoRemoteImages, assertNoScripts, assertMatchesSnapshot` via normalized `htmlSnapshot()` / `textSnapshot()` / `mimeTreeSnapshot()`).
- Snapshot normalization replaces Message-IDs, dates, ULIDs, MIME boundaries and CIDs with stable tokens; the raw file is never modified.
- Diagnostics artifact: `->diagnostics()` returns a structured array (parse status, limit hits, removed scripts/forms/iframes, remote images, tracking pixels, missing text alternative, missing subject, oversized HTML, links); `->saveDiagnostics($path)` writes JSON with `rules_version`; `->saveEml($path)` writes the raw stream unchanged; `->saveFixture($path)` writes a redacted JSON fixture (no Bcc, no context).

## 13. Console

- `mailbox:install` — publishes config (idempotent), prints the URL and the `.env` line; never edits `.env`.
- `mailbox:doctor [--repair] [--json]` — environment guard, `mail.mailers.local` collision, storage path writability, SQLite version/FTS5/journal mode, filesystem type heuristics (network mounts), config/route cache state, retention totals, orphan directories, stale tmp; exit code 1 on any critical finding.
- `mailbox:url`, `mailbox:clear [--force]`, `mailbox:prune`.
- `php artisan about` shows enabled state, path, storage, message count, journal mode.

## 14. Test strategy

- Unit: extractor, sanitizer (XSS corpus), CSS scrub, attachment policy, address normalization, limits, pruner, snapshot normalizer.
- Feature (Testbench): provider wiring (config merge, no overwrite, publish tag, about), transport end-to-end (Mailable, Notification, queued Mailable via sync/database queue, named mailer), Bcc invariants, raw byte equality, parse failure path, environment guard (production 404 + transport throws), routes/controllers (HTML, fragments, JSON, CSP headers, downloads, hostile filenames), commands, testing API.
- Fixtures: `tests/Fixtures/mime/*.php` builders producing plain, html, alternative, mixed, related/CID, nested, base64, QP, 8bit, unicode headers, RFC 2231 filenames, duplicate headers, calendar, nested rfc822, custom headers, oversize/overdepth; `tests/Fixtures/xss/*.html`.
- Concurrency: a Pest test spawns 8 PHP subprocesses (each capturing 100 messages through the real store) and asserts 800 rows, 800 raw files, no lock errors.
- Crash: `MessageRecorder` accepts an internal `FailureInjector` used only in tests to throw at each stage; assertions: no half-visible record, `doctor --repair` restores invariants.
- Browser: Playwright (through the MCP tools during vetting, and a `tests/Browser` Pest suite marked `@group browser` that skips when no browser is available) covers sandbox isolation, keyboard navigation, responsive layout and axe checks.

## 15. Orchestration plan (how this gets built)

1. Codex council (2 read-only reviewers: "simplicity & Laravel package conventions", "security & MIME/storage architecture") critiques this design; the orchestrator adjudicates and records outcomes in §16.
2. Plan written with `superpowers:writing-plans`; executed with subagent-driven development, TDD per task.
3. Backend tasks: Codex `task --write` engineers implement from the plan; a Claude reviewer verifies each task against the spec and the tests; the orchestrator runs `composer test` after each merge point.
4. UI: an Opus design agent produces the design system, Blade views and JS; a Codex adversarial reviewer challenges it for security and simplicity; the orchestrator vets visually with Playwright screenshots in the Testbench workbench and iterates until it meets §11.
5. Final gates: `composer validate --strict`, `composer audit`, Pint, Larastan (level max sustainable, ≥ 7), Pest incl. concurrency/crash/security suites, browser vetting, then docs (README, CHANGELOG, SECURITY, UPGRADE) and the owner-facing test/publish instructions.

## 16. Council outcomes

(filled after the council round)
