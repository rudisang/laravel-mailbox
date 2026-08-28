# Laravel Mailbox — implementation design (revision 2)

Status: approved for planning after council review (approval delegated to the orchestrating agent by the package owner on 2026-08-28).
Parent document: `2026-08-28-product-spec.md` (owner's product spec). This document records every decision that specification left open and the concrete shape of v1. Where the two disagree, this document wins because it is later and narrower. Revision 2 incorporates the Stage 0 audit and the two-reviewer council round (§16).

## 1. Identity

| Item | Decision |
|---|---|
| Composer name | `rudisang/laravel-mailbox` |
| PHP namespace | `Rudisang\Mailbox` |
| Config file / key | `config/mailbox.php` → `mailbox.*`; publish tag `mailbox-config` |
| Mail transport name | `local` (`MAIL_MAILER=local` must work with no `config/mail.php` edit) |
| Route prefix | `/_mailbox` (config `mailbox.path`) |
| Artisan commands | `mailbox:doctor`, `mailbox:clear`, `mailbox:prune` |
| Env vars | `MAILBOX_ENABLED`, `MAILBOX_PATH`, `MAILBOX_STORAGE_PATH`, `MAILBOX_NAMESPACE` |
| Testing entry points | trait `Rudisang\Mailbox\Testing\InteractsWithMailbox` (`$this->mailbox()`), global `mailbox()` helper (guarded by `function_exists`), `CapturedMessage` |
| Storage | `storage/framework/mailbox/` |
| License | MIT |

## 2. Platform and dependencies

- PHP `^8.2`; Laravel `^12.0 || ^13.0`; Symfony mailer/mime `^7.2 || ^8.0`; **`symfony/html-sanitizer: ^7.4.13 || ^8.0.13`** (patched floor for GHSA-x5qj-865h-mgvm); Testbench `^10 || ^11`; Pest `^4 || ^5`; Larastan `^3`; Pint.
- Runtime `require`: `php`, `ext-dom`, `ext-fileinfo`, `ext-mbstring`, `ext-pdo`, `ext-pdo_sqlite`, `illuminate/console`, `illuminate/contracts`, `illuminate/events`, `illuminate/http`, `illuminate/mail`, `illuminate/queue`, `illuminate/routing`, `illuminate/support`, `illuminate/view`, `symfony/html-sanitizer`, `symfony/mailer`, `symfony/mime`. (`ext-json` and `illuminate/filesystem` dropped — the former is always present on PHP ≥ 8, the latter is unused.)
- No Node at runtime and no build step: CSS and JS are hand-authored and committed under `resources/dist/`. Node + Playwright are **development-only** (`tests/Browser/package.json`) for the browser suite.
- Layout, scripts, Pint, Larastan (level 8), arch tests, `.gitattributes`, `SECURITY.md`, `release.yml`, `dependabot.yml` mirror `laravel/package-skeleton`; the CI matrix is written explicitly (§15).

## 3. Service provider contract

```
register():  mergeConfigFrom(mailbox); singletons: EnvironmentGuard, StoragePaths, MessageStore,
             MessageRecorder, ContextCollector, HtmlPreviewSanitizer;
             set mail.mailers.local = ['transport' => 'local'] only when config('mail.mailers.local') is null.
boot():      Mail::extend('local', ...);
             loadRoutesFrom(routes/web.php) ALWAYS (Laravel skips it when routes are cached; middleware fails closed);
             loadViewsFrom('mailbox');
             when guard allows: listen MessageSending / JobProcessing / JobProcessed / JobFailed /
               JobExceptionOccurred (provenance), Queue::createPayloadUsing (namespace propagation);
             when runningInConsole(): commands([...]), publishes(config => 'mailbox-config'), AboutCommand::add.
```

- No filesystem, PDO, or network work in `register()`/`boot()`; the SQLite connection opens lazily.
- The transport re-checks `EnvironmentGuard` **on every send**; outside allowed environments it throws `Rudisang\Mailbox\Exceptions\MailboxDisabledException extends Symfony\Component\Mailer\Exception\TransportException` before accepting the message.
- Config/route caching is tested explicitly.

## 4. Environment guard (fail closed)

```php
'enabled'      => env('MAILBOX_ENABLED'),   // null|true|false; can only DISABLE, never enable elsewhere
'environments' => ['local', 'testing'],
```

`allows()` = `enabled !== false && app()->environment(environments)`. `APP_DEBUG` is never consulted; `production` is refused even if listed. There is no override path in v1. Routes: `Authorize` middleware returns 404 when `!allows()`, then applies the optional `viewMailbox` gate when the app defines one. `doctor` flags `local` appearing inside any `mail.mailers.*.mailers` (failover/round-robin) array.

## 5. Capture pipeline (LocalTransport → MessageRecorder)

1. `doSend(SentMessage $sent)` → `EnvironmentGuard::assertAllowed()` → `MessageRecorder::record($sent, $mailerName)`.
2. Allocate ULID `$id`; create `tmp/{id}/`.
3. Stream `$sent->toIterable()` to `tmp/{id}/raw.eml` through a bounded writer (byte count + SHA-256; `MessageTooLargeException extends TransportException` past `limits.raw_bytes`). `fsync()` before close.
4. `$original = $sent->getOriginalMessage()`; `$envelope = $sent->getEnvelope()`; `$messageId = $sent->getMessageId()`.
5. Raw header block: read `raw.eml` up to the first empty line (bounded by `limits.header_bytes`), unfold continuation lines, decode display values with `iconv_mime_decode`. This is the source for **raw header facts** (`assertHeader`, `assertRawHeaderMissing`, Headers tab). No regex-based MIME parsing anywhere; the body is never parsed from raw.
6. If `$original instanceof Email`: `StructuredMessageExtractor` walks `$original->getBody()` recursively (`AbstractMultipartPart::getParts()`), producing an ordered tree of parts (position, depth, parent) under `limits.parts` / `limits.depth`. Each leaf's decoded body is streamed to `tmp/{id}/parts/{partUlid}.bin` by piping `bodyToIterable()` through PHP's `convert.base64-decode` / `convert.quoted-printable-decode` stream filter (7bit/8bit/binary pass through), computing decoded size and SHA-256 while writing; `fsync()` each. The extractor also records semantic recipients (`getFrom/getTo/getCc/getBcc/getReplyTo`), subject, html/text availability and bounded `preview_text`/`search_text`. Facts from this path are labelled **original** (structured) facts; boundaries are not recorded because `getBody()` regenerates them.
7. Any other `RawMessage`: raw + envelope only; `parse_status = unsupported`.
8. Extraction exceptions → `parse_status = partial|failed` with a stable `parse_error` code; raw is never discarded.
9. `ContextCollector::take($original)` → scalar provenance + namespace (§8).
10. Acquire shared `flock` on `storage/framework/mailbox/.lock`; `rename(tmp/{id}, messages/{id})`; `MessageStore::insert()` in one transaction with a `SQLITE_BUSY` retry loop (busy_timeout 5 s + 3 jittered attempts); release the lock. Maintenance (prune/repair/clear) takes the lock exclusively, so a directory can never be judged orphaned inside the rename→insert window.
11. Insert failure → remove `messages/{id}` → rethrow as `TransportException`.
12. After commit: dispatch `MessageCaptured($id, $seq, $namespace)` inside try/catch; opportunistic `prune()` inside try/catch (non-blocking exclusive lock; skipped if busy). Nothing after commit can fail the send.

Crash model (documented): captures are durable against **process termination** at any step; power-loss durability is best-effort (`fsync` on files, SQLite `synchronous=FULL`, rollback journal). A row whose files are missing renders as "raw missing" and is removed by `doctor --repair`.

## 6. Storage

```
storage/framework/mailbox/
├── .lock
├── index.sqlite
├── messages/{ulid}/raw.eml
├── messages/{ulid}/parts/{partUlid}.bin
└── tmp/{ulid}/...
```

SQLite via PDO directly (no Laravel DB connection). Pragmas: `busy_timeout=5000`, `foreign_keys=ON`, `synchronous=FULL`, `journal_mode=DELETE`. No FTS, no WAL, no filesystem heuristics in v1 — search is a bounded `LIKE` over `search_text` (1,000-row cap makes this sub-millisecond). Schema is created under the exclusive `.lock` (idempotent `CREATE TABLE IF NOT EXISTS` + `mailbox_meta.schema_version`).

`messages`: `seq INTEGER PRIMARY KEY AUTOINCREMENT` (commit sequence, the only safe cursor), `id` ULID UNIQUE, `schema_version, captured_at, message_id, raw_sha256, raw_bytes, mailer, parse_status, parse_error, subject, from_json, to_json, cc_json, bcc_json, reply_to_json, envelope_sender, envelope_recipients_json, tags_json, metadata_json, raw_headers_json` (ordered `[name, value]` pairs), `has_html, has_text, preview_text, search_text, part_count, attachment_count, decoded_bytes, read_at, namespace, context_json`.

`parts`: `id` ULID, `message_id, parent_id, position, depth, content_type, media_type, media_subtype, disposition, filename, content_id, charset, transfer_encoding, decoded_bytes, sha256, is_inline, is_attachment, preview_eligible`.

Retention (`Pruner`): `retention.days` 7, `retention.max_messages` 1000, `retention.max_bytes` 250 MiB, oldest first, under the exclusive lock; also `mailbox:prune`. `doctor --repair` (exclusive lock): remove `tmp/*` older than 10 min, remove `messages/*` without rows, remove rows without `raw.eml`.

## 7. Limits (`mailbox.limits`)

`raw_bytes` 50 MiB, `parts` 100, `depth` 30, `header_bytes` 256 KiB, `search_text_bytes` 512 KiB, `preview_bytes` 2 MiB (HTML handed to the sanitizer). Clamped to safe ranges on read.

## 8. Provenance (`ContextCollector`) — best effort, scalar, bounded

- `runtime` (`http|console|queue`), `mailer`, `environment`, `locale`, `request_method`, `request_path` (no query string), `command` (`argv[1]`).
- `mailable`, `notification`, `notification_id`: a `MessageSending` listener stores `{data keys, subject, to-addresses}` in a single send-scoped slot; the recorder consumes and clears the slot in the same synchronous send and uses it only when subject and To match the original message. The slot is also cleared on `MessageSent` and on transport failure.
- `job`, `job_id`, `queue`, `connection`: set from `JobProcessing` (`$job->resolveName()`, `$job->uuid()`), cleared on `JobProcessed`, `JobFailed`, `JobExceptionOccurred`.
- `tags`, `metadata`: `TagHeader` / `MetadataHeader` from the original message.
- App-supplied context: `Mailbox::context(array $scalars)` (cleared after next capture) and `Mailbox::redactContextUsing(callable)`. All values are cast to string, ≤ 64 keys, ≤ 1 KiB each, re-validated after redaction.

## 9. HTTP surface

All routes under `mailbox.path`, middleware `mailbox.middleware` (default `['web']`) + `Authorize`.

```
GET     /                                  inbox (full page; ?partial=list → list fragment)
GET     /messages/{id}                     detail (full page; ?partial=detail → detail fragment)
GET     /messages/{id}/preview/html        sanitized HTML document for the sandboxed iframe
GET     /messages/{id}/preview/text        escaped text/plain body in a hardened document
GET     /messages/{id}/raw                 raw.eml (text/plain; ?download=1 → .eml attachment)
GET     /messages/{id}/parts/{part}        allowlisted inline raster image, otherwise forced download
POST    /messages/{id}/read                {read: bool}
DELETE  /messages/{id}
POST    /clear
GET     /api/status?since={seq}            {seq, total, unread}  (ETag)
GET     /assets/{file}                     mailbox.css / mailbox.js from resources/dist, ?v={hash}, immutable
```

`{id}` and `{part}` are constrained to `[0-9A-HJKMNP-TV-Z]{26}` at the route level. No JSON list/detail endpoints and no CID route in v1 (CIDs resolve to the parts route at render time).

## 10. Preview security (HtmlPreviewSanitizer) — revised after council

Threat model: the preview iframe is an **opaque-origin, script-less sandbox**; the only resources it may load are `data:` URIs and package-generated part URLs for this message. HTML markup is sanitized; **CSS is not sanitized, it is contained** — CSS cannot execute script, and every `url()`, `@import`, `@font-face` fetch is blocked by the preview CSP. This is stated in `SECURITY.md`.

Pipeline (response time, no cache):

1. Parse with `DOMDocument` (libxml, no entity loading, input capped at `limits.preview_bytes`).
2. Collect the text of every `<style>` element (bounded); remove them from the tree.
3. Strip **every** author-controlled resource/navigation attribute from every element: `src, srcset, poster, background, lowsrc, dynsrc, ping, action, formaction, href, xlink:href, data, codebase, archive, longdesc, usemap`. Before stripping, record for diagnostics: link hrefs (canonically parsed; only `http`, `https`, `mailto` are kept as "openable"), remote image URLs (escaped text only), 1×1/hidden images (tracking pixels). For `<img src="cid:X">` where `X` maps to a known part of this message: set `data-mailbox-part="{partUlid}"`. For `<img src="data:image/...">`: keep as `data-mailbox-data` only when the payload is a `data:image/(png|jpeg|gif|webp);base64,` URI ≤ 512 KiB.
4. Serialize the body and run Symfony `HtmlSanitizer`: `allowSafeElements()`, `allowAttribute('style', '*')`, `allowAttribute('data-mailbox-part', 'img')`, `allowAttribute('data-mailbox-data', 'img')`, no link schemes, no media schemes, no relative links/media, `withMaxInputLength(limits.preview_bytes)`. Unknown elements/attributes are dropped by default (SVG, MathML, forms, iframes, objects, embeds, scripts, `<base>`, `<meta>` never survive).
5. Post-process the sanitized output with `DOMDocument` once more: `data-mailbox-part` (validated ULID present in the part map) → `src="{path}/messages/{id}/parts/{partUlid}"`; `data-mailbox-data` → `src` (re-validated). Anchors keep their text and gain `title="link neutralised"`; nothing else is added.
6. Emit the package-owned document: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>{original style blocks, verbatim}</style></head><body>{sanitized}</body></html>`.

Preview response headers: `Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'none'; connect-src 'none'; form-action 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'; frame-ancestors 'self'; sandbox`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`. The workbench embeds it with `<iframe sandbox src="…">` (empty token list). The workbench page sends `Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'`.

`'self'` in the preview `img-src` is only reachable through package-generated part URLs because step 3 removed every author-controlled URL; the parts route serves only sniffed raster images. Same-origin requests to arbitrary application URLs are therefore impossible from the preview.

Text preview: `htmlspecialchars` inside `<pre>` in the same hardened document shape.

Parts route: sniff with `finfo`; inline only for `image/png, image/jpeg, image/gif, image/webp, image/bmp` (and only when the sniffed type is on that list, regardless of the declared type); everything else is `application/octet-stream` + `Content-Disposition` via `HeaderUtils::makeDisposition('attachment', $safeName, $asciiFallback)` where `$safeName` has CR/LF/control characters removed, is NFC-normalised, and is capped at 120 characters; `Content-Length` from the blob; `nosniff`. Path resolution never uses filenames.

Links tab (workbench DOM, escaped Blade): text, canonical URL as text, and an **Open** anchor (`target="_blank" rel="noopener noreferrer"`) only for `http`/`https`/`mailto` URLs without control characters. Opening is a deliberate user click, never automatic.

## 11. Mailbox UI

Server-rendered Blade + `resources/dist/mailbox.css` + `resources/dist/mailbox.js` (ES module, no dependencies), served by the assets route with `?v={md5}` and immutable caching. No CDN, no fonts fetched.

Screens: inbox (two-pane ≥ 960 px, single pane below), detail (deep-linkable), empty state, 404. Detail tabs: HTML, Text, Headers, Envelope, MIME, Raw, Attachments, Links, Diagnostics. Toolbar: viewport presets (Phone 375 / Tablet 768 / Desktop 100 %), theme toggle (system/light/dark in `localStorage`), download `.eml`, mark unread, delete. Global: debounced server-side search, filters (unread, attachments, parse issues), unread badge, clear-all with confirm, shortcuts (`j/k`, `Enter`, `/`, `e`, `u`, `[`/`]`, `?`), live region for "N new messages".

Progressive enhancement: every action is a plain link or form and works with JavaScript off; JavaScript upgrades navigation to fragment swaps (`?partial=`), `pushState`, polling `/api/status` every 2 s while visible (30 s hidden, paused after 10 min idle), and shortcuts.

### Visual direction (brief for the design agent)

- Monochrome. Light: canvas `#FFFFFF`, surface `#FAFAFA`, ink `#0A0A0A`, muted `#6B6B6B`, hairline `#E6E6E6`. Dark is the exact inverse: canvas `#0A0A0A`, surface `#141414`, ink `#FAFAFA`, muted `#9A9A9A`, hairline `#262626`. One amber for warnings and one red for destructive actions, used sparingly.
- Typography: system UI stack (`-apple-system, BlinkMacSystemFont, "Segoe UI", Inter, Roboto, sans-serif`), tight tracking on headings, tabular numerals; `ui-monospace` for headers/raw/MIME.
- Shape: 16 px radius cards, 999 px pill buttons and chips, 10 px inputs; hairline borders instead of shadows in light mode, soft elevation in dark.
- Motion: 160 ms ease-out on hover/focus/tab changes, rows slide in on arrival, detail pane crossfades; honours `prefers-reduced-motion`.
- Feel: calm, dense but breathable, Linear/Vercel-grade polish. Nothing that reads as Bootstrap or a default admin template.
- Accessibility: 2 px visible focus rings, roles/labels on lists and tabs, WCAG 2.2 AA contrast, full keyboard operation.
- Budget: ≤ 250 KiB compressed for CSS + JS combined; target ≤ 40 KiB.

## 12. Testing API — revised after council

```php
uses(InteractsWithMailbox::class);

it('sends the final invoice email', function () {
    Mail::to('buyer@example.com')->cc('accounts@example.com')->send(new InvoiceMail($invoice));

    $this->mailbox()->latest()
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

- **Trait lifecycle.** `setUp`: snapshot `mail.default`, `mail.mailers.local`, `mailbox.*`; set `mail.default = local`; set `mailbox.namespace` to `MAILBOX_NAMESPACE` env or a fresh ULID per test; `Mail::purge('local')`; forget the package singletons. `tearDown`: restore the snapshot, purge again. Storage is the application's configured `mailbox.storage_path` (shared with any running worker) unless overridden — isolation comes from the namespace, not from private directories.
- **Namespace propagation.** `Queue::createPayloadUsing()` adds `mailbox_namespace` to every queued payload while the guard allows; the `JobProcessing` listener sets `mailbox.namespace` from the payload for the job's duration and restores it on `JobProcessed`/`JobFailed`/`JobExceptionOccurred`. Result: an already-running `queue:work` process attributes captures to the test that queued them. Documented fallback: `MAILBOX_NAMESPACE` env for workers started by hand.
- **Queries** scope to the current namespace by default: `latest()`, `all()`, `count()`, `find($id)`, `whereMessageId()`, `whereTo()`, `whereSubject()`, `whereTag()`, `anyNamespace()`, `assertNothingCaptured()`, `assertCaptured(int)`, `waitForCapture(int $count = 1, float $timeout = 5.0)` — polls `count(namespace, seq > highWater)` every 50 ms; the high-water `seq` is taken at trait `setUp` and after each `wait`.
- **Fact sources are labelled.** Raw header facts (`assertHeader`, `assertRawHeaderMissing`, `assertRawContains`) come from the raw header block; recipient/subject/body facts come from the original structured message; envelope facts from the `Envelope`. `CapturedMessage` exposes `id, seq, messageId, subject, from, to, cc, bcc, replyTo, envelopeSender, envelopeRecipients, html, text, raw, rawHeaders, parts, attachments, tags, metadata, context, parseStatus, diagnostics`.
- **Assertions:** `assertFrom, assertTo, assertCc, assertBcc, assertReplyTo, assertSubject, assertSubjectContains, assertHtmlContains, assertHtmlNotContains, assertTextContains, assertSeeInHtml, assertHasAttachment, assertAttachmentCount, assertHasInlineImage, assertHeader, assertRawHeaderMissing, assertRawContains, assertEnvelopeContains, assertEnvelopeSender, assertTag, assertMetadata, assertNoParseErrors, assertNoRemoteImages, assertNoScripts, assertMatchesSnapshot`.
- **Snapshots are field-aware:** `htmlSnapshot()` rewrites only `cid:` references to `cid:part-{n}`; `textSnapshot()` is verbatim; `headersSnapshot()` replaces only the values of `Message-ID` and `Date`; `mimeTreeSnapshot()` renders the structured tree (types, dispositions, filenames, sizes) without boundaries. Raw is never modified.
- **Diagnostics artifact:** `diagnostics()` returns `{rules_version, capture_id, namespace, results: [{rule, severity, message, evidence}]}` (rules: parse status, limit hits, removed scripts/forms/iframes/objects, remote images, tracking pixels, missing text alternative, missing subject, oversized HTML, neutralised links, Bcc present in raw for unsupported messages). `saveDiagnostics($path, 'json'|'junit')`, `saveEml($path)` (raw unchanged), `saveFixture($path)` (redacted JSON: no Bcc, no context).

## 13. Console

- `mailbox:doctor [--repair] [--json]` — guard state, `mail.mailers.local` collision, `local` inside failover/round-robin arrays, storage writability, SQLite version, config/route cache state, retention totals, orphan directories / dangling rows / stale tmp; exit 1 on any critical finding.
- `mailbox:clear [--force]`, `mailbox:prune`.
- `php artisan about` → Mailbox section (enabled, path, storage, messages).

## 14. Test strategy

- Unit: extractor + stream decoding, raw header block reader, sanitizer (XSS corpus incl. obfuscated CSS, `srcset`, SVG, `<meta refresh>`, `<base>`, data-attribute smuggling), attachment policy + disposition, address normalisation, limits, pruner, snapshot normaliser, diagnostics rules, JUnit writer.
- Feature (Testbench): provider wiring (config merge, no overwrite, publish tag, about, `config:cache`, `route:cache`), transport end-to-end (Mailable, Notification, queued Mailable through the `sync` and `database` drivers, named mailer), raw byte equality with `toIterable()`, Bcc invariants across raw / download / fixture / diagnostics, `RawMessage` unsupported path incl. a raw `Bcc:` header flagged, parse-failure path, guard (production 404 + transport throws + `MAILBOX_ENABLED=false`), every route (fragments, CSP headers, downloads, hostile filenames, ULID constraints), commands, testing API incl. namespace propagation through a real `queue:work --once` subprocess.
- Concurrency: 8 PHP subprocesses × 100 captures through the real recorder + concurrent prune/repair; 800 rows and 800 raw files, no lock error.
- Crash: `FailureInjector` (tests only) throws at every stage boundary (before write, after write, after fsync, after rename, before commit, after commit, in event, in prune); invariants: no half-visible message; `doctor --repair` restores.
- Browser (`tests/Browser`, Playwright, dev-only Node): sandbox isolation (no script, no navigation, no form submit, zero external and zero non-package same-origin requests), keyboard navigation, responsive layouts, axe. Also run interactively with the Playwright MCP during vetting.

## 15. CI matrix

| Job | Stack |
|---|---|
| L12 floor | PHP 8.2, Laravel 12.*, Testbench 10.*, `--prefer-lowest` |
| L12 latest | PHP 8.4, Laravel 12.*, Testbench 10.*, `--prefer-stable` |
| L13 floor | PHP 8.3, Laravel 13.*, Testbench 11.*, `--prefer-lowest` |
| L13 latest | PHP 8.5, Laravel 13.*, Testbench 11.*, `--prefer-stable` |
| Windows | PHP 8.4, Laravel 13.*, `--prefer-stable` |
| Browser | Ubuntu, PHP 8.4, Node 22, Playwright Chromium + WebKit |
| Quality | Pint, Larastan, `composer validate --strict`, `composer audit`, type coverage |

## 16. Council outcomes (adjudicated by the orchestrator)

Stage 0 audit: greenfield justified — Redberry and Mailroom both decompose to DTO columns at capture, require migrations/app-DB tables, and neither exposes final-MIME assertions, run isolation, or diagnostics; retrofitting is an architectural fork. Audit also flagged Message-ID divergence when a second `SentMessage` is minted; this design captures from the single `SentMessage` Laravel receives, so Message-IDs agree.

| # | Finding (A = conventions reviewer, B = security reviewer) | Decision |
|---|---|---|
| A1/B3 | `fflush` is not durable; `synchronous=NORMAL` risks dangling rows | **Accepted.** `fsync()` on every file, `synchronous=FULL`, rollback journal, crash model documented as process-termination durable / power-loss best-effort, dangling rows repaired. |
| A2/B6 | `bodyToIterable()` is transfer-encoded; flat `getAttachments()` cannot build a tree | **Accepted.** Walk `Email::getBody()`; decode through PHP stream filters; raw header block is the source of raw header facts; fact sources labelled. First implementation task is a spike proving the decoder within the memory budget. |
| A3 | Sanitizer range admits vulnerable versions | **Accepted.** `^7.4.13 \|\| ^8.0.13`. |
| A4/B1 | CSS pipeline is an ad-hoc sanitizer; delete all CSS | **Rejected in part.** Email is inline-styled; a style-less preview fails the product's core promise (faithful browser preview). CSS is *contained* (opaque sandbox + `default-src 'none'`), not sanitized; the ad-hoc CSS scrubber is deleted as a false control. Accepted the same-origin-request attack: all author URLs are stripped and only validated package part URLs are re-injected. |
| B2 | Hostile URLs retained in preview DOM | **Accepted.** No original URLs in the preview DOM; links/images are listed as escaped text in the workbench; Open action only for parsed http/https/mailto. |
| A5/B9 | Object-keyed provenance cannot work (transport clones the message); global "last job" misattributes | **Accepted with a smaller design.** Send-scoped slot verified by subject/To match, cleared on every terminal path; job context keyed to the active job and cleared on all terminal job events; bounded scalar allowlist. Provenance stays because it is a release-blocking differentiator. |
| A6/B5 | FTS/WAL over-engineered and inconsistent; busy timeout alone insufficient | **Accepted.** No FTS, no WAL, `LIKE` scan; busy timeout + jittered retry; schema init under the lock. |
| A7 | Conditional route loading breaks route caching | **Accepted.** Routes always load; middleware fails closed. |
| B4 | Repair/prune race with the rename→insert window | **Accepted.** Shared/exclusive `flock` on `.lock`; tmp lease of 10 min. |
| B7 | Arbitrary `RawMessage` may carry a raw `Bcc:` | **Accepted (guarantee narrowed).** No-Bcc-in-raw applies to Symfony `Message` instances; `unsupported` captures are flagged by a diagnostic rule when the raw header block contains `Bcc`. |
| B8 | Guard override path too weak | **Accepted.** No enable-override in v1; `enabled` can only disable; re-checked per send; doctor flags failover composition. |
| A8 | Browser tests contradictory | **Accepted.** Playwright is a dev-only Node dependency under `tests/Browser`; CI job runs Chromium + WebKit. |
| A9 | Trait config mutation underspecified | **Accepted.** Snapshot/restore + `Mail::purge` + singleton reset. Trait method is primary; global helper kept for Pest ergonomics. |
| B10 | Namespaces invisible to running workers; ULIDs unsafe cursors | **Accepted.** Shared store + `Queue::createPayloadUsing` propagation + `seq` high-water mark. |
| B11 | Global snapshot replacement hides regressions; no JUnit | **Accepted.** Field-aware normalisation; JSON + JUnit writers with rule IDs. |
| A10 | CI matrix explicit | **Accepted.** §15. |
| A cut list | `install`, `url`, About, JSON endpoints, CID route, asset route, theme/shortcuts/Links/fragments, store interfaces, unused deps | **Accepted:** `install`, `url`, JSON list/detail, CID route, `MessageStore`/`BlobStore`/`AttachmentPolicy` interfaces, `ext-json`, `illuminate/filesystem`. **Kept:** About (3 lines, Laravel convention), assets route (immutable caching beats inlining 40 KiB per page), theme/shortcuts/fragment navigation (owner requirement: fluid, dark-mode UI), Links tab (required once links are neutralised in the preview). |
