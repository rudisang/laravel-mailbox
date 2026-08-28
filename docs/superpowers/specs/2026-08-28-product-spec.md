# Laravel Local Mail Workbench — product and engineering specification

Status: research-backed design proposal (owner-supplied, 28 August 2026). Naming in this document (`local-mail`, `Vendor\LocalMail`, `/_local-mail`) is superseded by the implementation design (`rudisang/laravel-mailbox`, `Rudisang\Mailbox`, `/_mailbox`); every rule below still applies.

## 1. Executive decision

Build an embedded Laravel development transport and mail workbench, not a new SMTP server. The package registers a Laravel mail transport named `local`. When Laravel finishes rendering a Mailable or Notification, the transport synchronously captures the exact prepared RFC 822/MIME stream, delivery envelope, and protected original-recipient metadata. A private, same-application URL presents the captured mail in a polished mailbox and exposes assertions against the final rendered result.

Promise: the trustworthy Laravel-native view of the email your application actually generated — canonical MIME, envelope truth, secure previews, final-output assertions, deterministic diagnostics — with no daemon or cloud account. It must NOT promise pixel-identical Gmail/Outlook/Apple Mail/Yahoo rendering.

Product gate: greenfield only if at least three of these differentiators cannot be delivered cleanly upstream (Redberry Mailbox, Laravel Mailroom): (1) assertions against final prepared MIME/envelope/MIME tree/queued delivery; (2) deterministic rendered-output snapshots and machine-readable CI artifacts; (3) security and compatibility diagnostics with versioned rules; (4) Laravel execution provenance and cross-process/test-run isolation; (5) crash-safe streaming capture with bounded MIME extraction and verified concurrency.

## 2. Architecture vote

All three research tracks chose the embedded direct transport (scores 8.43 / 8.11 / 8.53) over a hybrid HTTP receiver or SMTP sidecar. V1 indexes normal Laravel `Email` instances from Symfony's structured MIME tree while the exact prepared raw stream remains authoritative. Arbitrary `RawMessage` input is captured raw with envelope metadata and `parse_status=unsupported`. Never grow an ad-hoc MIME parser.

## 3. Market position (wedge)

Operates at Laravel's final transport seam: sees the final post-render Symfony message; preserves original Bcc classification before Symfony strips the Bcc header; associates captures with Laravel runtime context and test namespaces; asserts on the exact result without `Mail::fake()`, including queued/cross-process mail; requires no SMTP port, daemon, Docker, cloud account, Node runtime, or host frontend integration.

## 4. Goals and non-goals

V1 goals: one-command dev install and `MAIL_MAILER=local`; synchronous durable capture of the final prepared MIME stream; preserve headers, envelope, original To/Cc/Bcc/Reply-To, HTML/text alternatives, inline CID resources, attachments; fast accessible inbox with search, read state, raw/source views, safe attachments, responsive viewport previews; Pest/PHPUnit assertions over final captured output incl. real queue workers; correct under package discovery, config caching, route caching, queues, parallel tests, long-running workers; fail closed and loudly outside allowed environments; lightweight when unused, bounded on hostile input.

V1 non-goals: SMTP/IMAP/POP3/AUTH/TLS/DKIM/SPF/DMARC/inbox placement; compose/reply/contacts/folders/accounts; forwarding/relay/"capture and send"/production observability; team/cloud/multi-tenancy/RBAC; exact named-client emulation; server-side remote image proxying, link checking, screenshots; open/click tracking; S/MIME/PGP; stable third-party HTTP API; Reverb/Redis/WebSockets/Docker/Node/persistent services; non-Laravel frameworks.

## 5. Supported platform

Laravel 12 (PHP ^8.2, Symfony ^7.2, Testbench ^10) and Laravel 13 (PHP ^8.3, Symfony ^7.4 || ^8.0, Testbench ^11). Require only the Illuminate components used. Bounded caret constraints. No `version` field; releases from signed VCS tags.

## 6. Installation and developer experience

`composer require --dev`, `MAIL_MAILER=local`, visit the mailbox URL. Auto-discovery registers the provider; provider supplies `mail.mailers.local = ['transport' => 'local']` only when the app has not defined it; application configuration always wins and collisions are a `doctor` finding; never edit `.env` or app files; no migration/asset publication/frontend build; optional idempotent `install` command; `doctor` checks environment guards, mailer collisions, directories, permissions, SQLite capabilities/version/filesystem, config/route caching, retention, orphan state; `url`, `clear`, `prune` commands; `php artisan about` integration. Follow the official Laravel package skeleton with Workbench, Pest, Larastan and Pint.

## 7. Laravel integration contract

`register()`: merge config; bind store/extractor/clock/identifier/context contracts; add nested `mail.mailers.local` default without replacing app values; no routes/listeners/commands/filesystem/database work. `boot()`: `Mail::extend('local', ...)`; guarded routes and namespaced views; commands, `about` data, named publish groups; no storage/network access until used. Config files contain no closures and call `env()` only inside config. `mergeConfigFrom` is shallow, so nested mail defaults need an explicit default-only merge. Test `config:cache` and `route:cache`.

Transport: extends Symfony `AbstractTransport`, implements `doSend(SentMessage $message): void`, stable non-secret `__toString()`. Do not replace `MailManager` or the core provider. Do not rely on `$this` inside manager extension callbacks. Avoid named arguments on Laravel APIs.

Semantics: `MessageSending` may cancel before the transport; the transport accepts only after durable capture; capture failure throws a Symfony `TransportException`; `MessageSent` is emitted only after successful capture; queued failures follow normal retry policy; at-least-once duplicates are possible and must never be silently deduplicated by Message-ID, subject, or content hash.

## 8. Capture pipeline and consistency model

Mailable/Notification → render → `MessageSending` → `AbstractTransport`/`SentMessage` (original structured Email → protected Bcc + semantic metadata; delivery Envelope → actual sender/recipients; prepared `toIterable()` → canonical raw.eml) → bounded structured MIME extraction → private blobs + SQLite index → durable commit → `MessageSent` → UI and test API.

Sequence: (1) verify environment or throw; (2) monotonic ULID + private staging dir; (3) stream `toIterable()` to `raw.eml` computing bytes and SHA-256 — never `toString()` for large messages; (4) enforce total byte limit while streaming; (5) read protected metadata from `getOriginalMessage()` and delivery facts from `getEnvelope()`; (6) for `Email`, extract MIME tree/alternatives/parts from the original structured message under limits; for other `RawMessage`, raw + envelope only, `unsupported`; (7) stream part bodies to opaque blob paths where Symfony exposes a stream; never buffer whole large attachments to index them; filenames are display metadata only; (8) build normalized metadata and bounded search text; (9) flush, atomically rename staging dir to final ULID dir, then insert in a short transaction; (10) on transaction failure remove or mark the directory; `doctor --repair` handles orphans; (11) only after durable commit return success and emit `MessageCaptured`.

The SQLite index is authoritative for listing; `raw.eml` for content. Never visible before all bytes are durable. Pruning, listeners, analytics, debug logging must never turn a committed capture into a transport failure.

MIME extraction: extract headers, alternatives, attachments, dispositions, encodings, Content-IDs from the structured Symfony `Email`; preserve the raw stream byte-for-byte. Never implement header decoding, boundary splitting, charset handling, or transfer decoding with regexes. Raw imports must use a maintained bounded streaming parser behind an interface (candidate `zbateson/mail-mime-parser` ≥ 4.0.2). Extraction failure never destroys the canonical message: `parse_status=partial|failed|unsupported` with a stable error code and raw access.

## 9. MIME and envelope invariants

1. `raw.eml` is the exact prepared stream from `SentMessage::toIterable()`. 2. Immutable after commit; never reconstructed from columns. 3. Original structured `Email`, prepared message, and delivery `Envelope` are different facts stored separately. 4. Symfony removes Bcc from prepared headers; original Bcc lives only in protected developer metadata and is never reinserted into raw export, forwarded MIME, or recipient-visible headers (RFC 5322 §3.6.3). 5. Envelope recipients are not classified as To/Cc/Bcc. 6. Each capture has its own ULID; Message-ID and content hash are indexed facts, never uniqueness keys. 7. Preserve MIME ordering, Content-Type parameters, disposition, charset, transfer encoding, Content-ID, normalized filename metadata. 8. `cid:` resolution is scoped to the message; it never rewrites `raw.eml`. 9. Golden corpus: nested `message/rfc822`, calendar, malformed-but-storable, duplicate headers, Unicode/encoded-word headers, RFC 2231 filenames, base64, quoted-printable, 8-bit. 10. Never persist serialized PHP/Mailable/Notification/Symfony/closure/view-data objects.

## 10. Storage and data model

Default `storage/framework/<package>/` with `index.sqlite`, `messages/{ulid}/raw.eml`, `messages/{ulid}/parts/{opaque}.bin`, `tmp/`. SQLite stores metadata and bounded searchable text only.

Message record: ULID, schema version, UTC capture timestamp; Message-ID, raw SHA-256, raw byte count; mailer/transport name, parse status and error code; subject; normalized From/To/Cc/Bcc/Reply-To; envelope sender and recipients; tags and metadata headers; HTML/text availability and bounded preview/search text; part/attachment counts and decoded bytes; read state; optional test-run namespace; best-effort scalar provenance explicitly marked absent when unavailable.

Part record: opaque ID, parent/order, Content-Type and safe media family, disposition, display filename, Content-ID, charset/transfer encoding, encoded/decoded sizes, SHA-256, blob path, inline flag, preview eligibility.

Provenance: only supported scalar runtime facts available at capture time (selected mailer, environment, locale, request/CLI/queue context, job/correlation ID, opt-in tags). Mailable/Notification class or Blade view is best-effort, never promised. Never serialize message/view state. Provide an opt-in scalar context API and a redaction callback.

SQLite policy: local filesystem only, warn/refuse network filesystems and multi-replica layouts; bounded busy timeout and short write transactions; WAL only when the linked SQLite version (≥ 3.51.3 for the WAL-reset fix) and filesystem are proven safe, rollback journal otherwise; feature-detect FTS5 with a tested `LIKE` fallback; package-owned idempotent crash-safe schema migrations under a lock, no host migration; internal `MessageStore` contract, not public until proven.

Default limits: retention 7 days, 1,000 messages, 250 MiB total, 50 MiB raw, 100 parts, depth 30, 200 headers per part, 256 KiB header bytes per part, 512 KiB indexed text. Configurable within validated ranges; prune opportunistically after capture and via command; converge within one-message slack; oversize legitimate mail is kept raw with a partial index.

## 11. HTTP surface and mailbox UI

Configurable prefix. Endpoints: inbox, JSON list/detail, HTML/text preview, raw, parts, read toggle, delete, clear. All internal and unversioned in v1.

Inbox: newest-first; two/three-pane desktop and single-pane mobile; search subject/addresses/indexed text/mailer/tags/Message-ID/date; filters for read, attachments, parse errors, mailer, test run, date; summary shows From, To/Cc/Bcc, envelope, subject, time, size, capture/provenance identifiers; tabs for HTML, text, headers, envelope, MIME tree, raw, attachments; CID resolves only inside the message; read/unread, delete, bulk delete, clear, copy, `.eml` download; responsive viewport presets and dark/light theme not labelled as client emulators; parse/limit/security warnings visible; keyboard-complete navigation, visible focus, focus restoration, semantic lists/tables, live-region notices, WCAG 2.2 AA.

Frontend: semantic server-rendered HTML plus a small progressive JS layer; immutable content-hashed assets bundled in the package; host never runs npm/Vite/publish; no CDN or third-party request. Budgets: ≤ 250 KiB compressed JS+CSS; no runtime frontend dependency on the host; unused provider boot overhead < 2 ms; visibility-aware 2 s polling with ETag/`since` cursor and backoff; no WebSockets/SSE.

## 12. Security and privacy hard rules

Environment/access: `require-dev`; allowed environments `local` and `testing` from explicit configuration, never `APP_DEBUG`; outside allowed environments routes 404 and the transport throws loudly, never silently capturing; any non-local override needs explicit enablement plus access middleware/gate (safest: prohibit production entirely); never place `local` in failover/round-robin mailers; mutations use `web` middleware/CSRF and authorization; no CORS.

HTML preview: email HTML never enters the mailbox DOM; separate preview response in an iframe with an empty-token `sandbox`; sanitize active markup and unsafe URL attributes with a maintained sanitizer at a patched security floor (Symfony HTML Sanitizer; note advisory GHSA-x5qj-865h-mgvm); preview CSP `default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'self' data:; connect-src 'none'; form-action 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'`; `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, frame-ancestor policy; block all remote images/fonts/media by default, no server proxy; sanitize at response time or key caches by sanitizer version.

Attachments: filenames and declared MIME types are hostile display metadata; opaque IDs in private directories; downloads as `application/octet-stream` with RFC-compliant `Content-Disposition: attachment`, authoritative length, `nosniff`; inline preview allowlist-only (safe raster images), HTML/SVG/XML/CSS/JS/YAML/executables and text-shaped active formats are escaped source or download-only; strip CR/LF/control characters and cap filename length; test traversal, absolute paths, duplicates, reserved Windows names, Unicode normalization, control characters, header injection.

Network/logging: capture and browsing make no network requests; no link checker in v1; ordinary logs never contain bodies, addresses, subjects, filenames, tokens, or raw MIME (debug success logs: capture ID, counts, bytes, duration only); document that local captures contain secrets.

## 13. Testing API

Separate final-output workflow that does not alter `Mail::fake()`. Trait creates a unique test-run/process namespace and selects the `local` mailer without permanent global mutation; assertions operate on committed captures and canonical MIME facts; `waitForCapture()` with bounded timeout, no sleeps; queries by capture ID, Message-ID, tag, recipient, subject, namespace; snapshots of normalized HTML/text/MIME tree with documented volatile-value normalization, never mutating raw; machine-readable JSON/JUnit-compatible diagnostics for CI; deliberate `.eml`/sanitized fixture export redacting protected metadata by default; assertion classes and testing contracts public, controllers/schema/internal JSON private. Visual screenshot regression is a v1.1 companion unless achievable without Node/Chromium.

## 14. Package structure

`src/` with Provider, Contracts, Transport, Capture, Mime, Storage, Security, Http (Controllers/Middleware/Responses), Testing (trait, CapturedMessage, Assertions), Console, Events; `config/`, `routes/web.php`, `resources/views/`, `resources/dist/` (immutable prebuilt assets), `tests/` (Unit, Feature, Browser, Concurrency, Fixtures/mime), `workbench/`. PSR-4, PSR-3, PSR-12.

## 15. Acceptance criteria (release-blocking, automated)

1. Fresh Laravel 12 and 13 apps need only installation and `MAIL_MAILER=local`. 2. Discovery, `config:cache`, `route:cache`, `optimize`, Notifications, queued Mailables, named mailers, long-running workers work. 3. Raw output is byte-for-byte the prepared `toIterable()` stream. 4. Bcc appears in protected metadata and envelope facts but never in raw `.eml`. 5. Golden MIME fixtures cover the full corpus incl. malformed and oversize/overdepth. 6. Parser failure preserves downloadable raw with visible status; durable storage failure throws a transport exception. 7. XSS fixtures (scripts, handlers, forms, iframes, `javascript:`, meta refresh, base, SVG, CSS URLs, tracking pixels) execute nothing, submit nothing, navigate nothing, access no storage, make no external request. 8. Hostile filenames never affect paths or inject headers. 9. Eight concurrent worker processes × ≥ 100 messages: no lost/torn records, no unhandled lock error; duplicates remain distinguishable. 10. Simulated termination at every stage leaves a complete visible capture or a repairable invisible orphan. 11. Retention converges within one-message slack without corrupting in-flight captures. 12. Performance on declared CI hardware: 100 KiB mail p95 capture ≤ 100 ms; 10 MiB attachment p95 ≤ 750 ms; incremental peak memory ≤ 32 MiB; first inbox page over 1,000 messages p95 ≤ 150 ms. 13. UI assets ≤ 250 KiB compressed, no third-party requests. 14. No serious/critical axe issues; all actions keyboard-operable. 15. Production: routes 404 and the transport throws; no silent capture. 16. `composer validate --strict`, `composer audit`, static analysis at max adopted level, formatter, all suites, lowest/latest dependency jobs pass.

## 16. CI and quality gates

Matrix: floor (PHP 8.2 + L12 + Symfony 7.2 + Testbench 10), L12 latest, L13 floor (PHP 8.3 + L13 + Symfony 7.4 + Testbench 11), L13 latest (PHP 8.4/8.5 + Symfony 8), Windows + Linux filesystem smoke, SQLite variants (FTS5 on/off, WAL eligible/ineligible), browser (Chromium and WebKit). Both `--prefer-lowest` and latest. Require Pest/Testbench suites, browser/security tests, real cross-process concurrency tests, property/fuzz tests where practical, Larastan at the highest sustainable level, Pint, `composer validate --strict`, `composer audit`, dependency review, pinned sanitizer floors, coverage/type-coverage floors, signed tags, generated changelog, upgrade guide, `SECURITY.md`, support policy.

## 17. Release plan

Stage 0 evidence/extension audit → 0.1 canonical capture kernel (skeleton, transport, guard, streaming storage, envelope/original metadata, SQLite schema, repair/clear/prune/doctor, MIME corpus, concurrency/crash tests) → 0.2 secure mailbox (inbox/search/read/delete, all tabs, attachments/CID, `.eml`, sanitizer/sandbox/CSP, hostile attachment suite, accessibility/performance) → 0.3 workbench differentiators (assertion API, namespaces and worker wait/query, snapshots and JSON artifacts, versioned diagnostic rules) → 1.0 readiness (all gates pass; public API limited to config, commands, events, testing contracts). Later only with evidence: HTTP ingest receiver, stable store adapter, visual-regression companion, compatibility dataset, SMTP-ingest adapter.

## 18. Hard rules checklist

1. MUST use Laravel's documented custom transport extension; never replace core mail management.
2. MUST make `MAIL_MAILER=local` work without manual `config/mail.php` edits.
3. MUST preserve application configuration and detect transport/mailer collisions.
4. MUST store the exact prepared MIME stream and never reconstruct it.
5. MUST keep original semantic recipients, delivery envelope, and canonical headers distinct.
6. MUST never insert Bcc into recipient-visible/raw/exported MIME.
7. MUST durably commit before transport success; failures throw `TransportException`.
8. MUST preserve raw mail when parsing fails.
9. MUST use bounded streaming where source APIs permit it and enforce bytes/parts/headers/depth limits.
10. MUST extract normal Laravel mail from Symfony's structured `Email`; arbitrary raw input degrades explicitly to raw-only. Never parse MIME with regexes.
11. MUST never serialize persisted PHP/Symfony/Laravel objects.
12. MUST use private opaque storage paths and atomic writes with crash repair.
13. MUST treat HTML and attachments as hostile.
14. MUST use sanitizer + empty-token iframe sandbox + preview CSP; no message HTML in app DOM.
15. MUST block remote content and all server-side fetching by default.
16. MUST force safe attachment downloads and allowlist any inline preview.
17. MUST fail closed outside explicitly allowed environments; never use `APP_DEBUG` as the guard.
18. MUST protect mutations with authorization and CSRF; no CORS in v1.
19. MUST not include automatic relay/forward/tee behavior or production failover composition.
20. MUST keep sensitive email content out of logs.
21. MUST work under cached config/routes, queues, parallel tests, and long-running workers.
22. MUST test the real transport; `Mail::fake()` alone is insufficient.
23. MUST document at-least-once duplicates and never deduplicate by Message-ID/content alone.
24. MUST not claim exact Gmail/Outlook/Apple rendering.
25. MUST not ship a generic clone: three workbench differentiators are release-blocking.
26. SHOULD install under `require-dev` and support Laravel 12–13 initially.
27. SHOULD require only used Illuminate components and bounded dependency versions.
28. SHOULD remain lazy when unused and meet the declared runtime/asset budgets.
29. SHOULD expose small testing contracts while keeping storage/HTTP internals private until proven.
30. SHOULD emit package events only after durable commit and never allow observers to invalidate committed capture.

## 19. Open decisions resolved by implementation spikes

1. Extend vs greenfield (audit). 2. Extraction within memory budget; parser adapter timing. 3. Sanitizer fidelity: allowlist retaining useful email markup while blocking active content and remote CSS URLs. 4. SQLite journal mode across supported PHP builds. 5. Whether one private SQLite store suffices for v1. 6. Which Laravel request/queue/context facts are available through supported APIs. 7. Compatibility dataset licensing/update model. 8. Visual snapshot runner placement.

## 20. Primary references

Laravel 13 packages, package skeleton, custom mail transports, MailManager/Mailer source; Symfony Mailer, AbstractTransport, SentMessage, Mime Message; Orchestra Testbench; Composer schema and version constraints; OWASP XSS/HTML5/CSP/SSRF/File Upload cheat sheets; RFC 5322, 2045, 2046, 2183, 2231, 2392; SQLite WAL documentation; WCAG 2.2; Gmail CSS support; Can I Email.
