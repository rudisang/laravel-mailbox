# Laravel Mailbox

Laravel Mailbox is a Laravel-native local mail workbench. It captures the exact prepared RFC 822/MIME stream your application hands to the mail transport, preserves the delivery envelope and original recipient data, and gives you a private mailbox plus assertions against the final rendered result—without a daemon, Docker, or a cloud account.

The promise is a trustworthy view of the email Laravel actually generated: canonical MIME, envelope truth, secure previews, final-output assertions, and deterministic diagnostics.

> [!IMPORTANT]
> There is no Gmail, Outlook, or Apple Mail rendering emulation. Laravel Mailbox does not promise pixel-identical rendering in any named email client.
>
> Never enable Laravel Mailbox in production. The environment guard refuses production even if it is added to the configured environment list, and the transport throws instead of silently capturing.

## Requirements

- PHP 8.2 or newer
- Laravel 12 or 13
- The DOM, Fileinfo, Mbstring, PDO, and PDO SQLite PHP extensions

## Installation

Install the package as a development dependency:

```bash
composer require --dev rudisang/laravel-mailbox
```

Select the package's `local` transport in your application's `.env` file:

```dotenv
MAIL_MAILER=local
```

Package discovery registers the transport and its routes. Send mail normally, then open `/_mailbox` in the same Laravel application.

The package adds `mail.mailers.local` only when that mailer name is not already configured. Run `php artisan mailbox:doctor` if the UI is unavailable or messages are not captured.

To customize the defaults, publish the configuration:

```bash
php artisan vendor:publish --tag=mailbox-config
```

## What gets captured

Each successful transport handoff stores:

- the exact prepared raw message stream as `raw.eml`; this is the authoritative final MIME output;
- the delivery envelope sender and recipients;
- the original structured From, To, Cc, Bcc, and Reply-To fields, including Bcc before Symfony removes it from the transmitted header block;
- the subject, HTML and text alternatives, MIME tree, inline CID resources, and attachments;
- bounded Laravel execution context, test namespace, and parse status. Versioned diagnostics are derived deterministically from the capture when requested.

Arbitrary Symfony `RawMessage` instances are still captured as raw bytes with envelope metadata, but structured extraction is marked `unsupported`. The package does not implement an ad-hoc MIME parser.

## The mailbox UI

The server-rendered inbox has search, unread and attachment filters, read state, delete and clear actions, and responsive two-pane navigation. A message detail view provides these tabs:

- HTML
- Text
- Headers
- Envelope
- MIME
- Raw
- Attachments
- Links
- Diagnostics

HTML previews can be switched between Phone (375 px), Tablet (768 px), and Desktop (100%) widths. The theme cycles through system, light, and dark and is remembered locally. Links and forms remain usable without JavaScript; live updates, fragment navigation, shortcuts, tabs, viewport presets, and theme switching are progressive enhancements.

### Keyboard shortcuts

| Key | Action |
|---|---|
| `j` | Select the next message |
| `k` | Select the previous message |
| `Enter` | Open the selected message |
| `/` | Focus search |
| `e` | Delete the open message |
| `u` | Toggle the open message's read state |
| `[` | Select the previous detail tab |
| `]` | Select the next detail tab |
| `?` | Open shortcut help |
| `Esc` | Close help or return to the list on a small screen |

## Security model

Laravel Mailbox is a development tool, not a production observability service.

- Capture and routes are allowed only in the configured `local` and `testing` environments by default. `MAILBOX_ENABLED=false` can force the package off; no setting can force it on in production.
- Disallowed routes return 404 and the transport throws `MailboxDisabledException`. If the application defines a `viewMailbox` gate, the package also requires that gate to allow access.
- Email HTML is rendered in a separate opaque-origin iframe with an empty-token sandbox. Active markup and author-controlled resource/navigation attributes are removed, and a restrictive CSP blocks scripts, connections, forms, frames, objects, fonts, and remote resources.
- CSS is contained, not sanitized. Author CSS can affect the sandboxed preview visually, but its network fetches are blocked by the preview CSP and it cannot enter the mailbox page DOM.
- Inline display is limited to allowlisted raster images whose bytes match the detected type. Other attachments are served as `application/octet-stream` downloads with hardened filenames and `nosniff`.
- Link destinations are shown as text. Only canonical `http`, `https`, and `mailto` links receive an Open action, and opening one always requires a deliberate click.
- Capture and browsing do not proxy remote images or check links and do not make network requests on behalf of a message.

Captures contain secrets: bodies, addresses, subjects, attachments, links, and tokens may all be sensitive. Keep the storage private and use `php artisan mailbox:clear` or `php artisan mailbox:prune` to remove captures you no longer need.

See the [security policy](.github/SECURITY.md) for the complete threat-model summary and private reporting channel.

## Testing

Laravel Mailbox tests the final output at the transport boundary instead of replacing mail delivery with `Mail::fake()`.

Add `InteractsWithMailbox` to a Laravel test case. Pest users can register it directly:

```php
<?php

declare(strict_types=1);

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;

uses(InteractsWithMailbox::class);

it('captures the final invoice message', function (): void {
    $invoice = Invoice::factory()->create();

    Mail::to('buyer@example.com')->send(new InvoiceMail($invoice));

    $this->mailbox()->latest()
        ->assertTo('buyer@example.com')
        ->assertSubject('Your invoice')
        ->assertHtmlContains('Invoice #123')
        ->assertHasAttachment('invoice-123.pdf', 'application/pdf')
        ->assertRawHeaderMissing('Bcc')
        ->assertEnvelopeContains('buyer@example.com')
        ->assertNoParseErrors();
});
```

`$this->mailbox()` and the guarded global `mailbox()` helper return the same `MailboxTester`. The helper throws a clear exception if the trait has not initialized the tester.

### Queries and capture lifecycle

| Method | Result |
|---|---|
| `namespace()` | Current test namespace, or `null` for an unscoped tester |
| `latest()` | Newest capture in scope; fails when none exists |
| `all()` | All captures in scope, newest first |
| `count()` | Capture count in scope |
| `find($id)` | Capture by ULID when it belongs to the current scope |
| `whereMessageId($id)` | Captures with the exact message ID |
| `whereTo($address)` | Captures with an original To recipient, case-insensitively |
| `whereSubject($subject)` | Captures with the exact subject, case-insensitively |
| `whereTag($tag)` | Captures containing the tag |
| `anyNamespace()` | A new tester that queries every namespace |
| `assertCaptured($count)` | Assert the scoped capture count |
| `assertNothingCaptured()` | Assert that the scoped capture count is zero |
| `waitForCapture($count = 1, $timeout = 5.0)` | Poll for new captures after the high-water sequence and return them newest first |
| `clear()` | Delete every capture in the tester's current scope |

`waitForCapture` is intended for queued or cross-process delivery. It polls every 50 ms and advances its high-water sequence after each successful wait, so an earlier message does not satisfy a later wait.

### Assertions and their fact sources

| Fact source | Assertions |
|---|---|
| Original structured message | `assertFrom`, `assertTo`, `assertCc`, `assertBcc`, `assertReplyTo`, `assertSubject`, `assertSubjectContains`, `assertHtmlContains`, `assertHtmlNotContains`, `assertTextContains`, `assertSeeInHtml`, `assertHasAttachment`, `assertAttachmentCount`, `assertHasInlineImage`, `assertTag`, `assertMetadata`, `assertNoParseErrors` |
| Raw prepared header block or raw stream | `assertHeader`, `assertRawHeaderMissing`, `assertRawContains` |
| Delivery envelope | `assertEnvelopeContains`, `assertEnvelopeSender` |
| Versioned diagnostics over the captured output | `assertNoRemoteImages`, `assertNoScripts` |
| Field-aware HTML snapshot | `assertMatchesSnapshot` |

The distinction matters for Bcc: `assertBcc` checks the protected original recipient data, while `assertRawHeaderMissing('Bcc')` checks the final raw header block.

`CapturedMessage` also exposes the captured facts through methods: `id`, `seq`, `messageId`, `subject`, `from`, `to`, `cc`, `bcc`, `replyTo`, `envelopeSender`, `envelopeRecipients`, `rawHeaders`, `header`, `headers`, `html`, `text`, `raw`, `parts`, `attachments`, `attachmentContent`, `tags`, `metadata`, `context`, `parseStatus`, `parseError`, `diagnostics`, `links`, and `record`.

### Namespaces and real queue workers

The trait sets `mail.default` to `local`, keeps a non-empty configured `mailbox.namespace` (including `MAILBOX_NAMESPACE`) or creates a fresh ULID otherwise, purges the local mailer, and restores the previous configuration during teardown. Storage remains shared, so isolation works across processes rather than hiding messages in a process-private directory.

While the guard allows capture, the package adds `mailbox_namespace` to Laravel queue payloads. A worker adopts that namespace for the job and restores its previous namespace after the job finishes or fails. This supports workers that were already running when the test dispatched the job. For a worker started manually outside that propagation path, start both producer and worker with the same `MAILBOX_NAMESPACE` value.

Queue delivery is at-least-once. If a job is retried and hands the same message to the transport more than once, every capture is kept; Laravel Mailbox does not deduplicate attempts.

### Snapshots and artifacts

The snapshot methods normalize only fields whose instability is understood:

- `htmlSnapshot()` rewrites known `cid:` references to stable `cid:part-{n}` tokens;
- `textSnapshot()` returns the text body verbatim;
- `headersSnapshot()` replaces only `Message-ID` and `Date` values;
- `mimeTreeSnapshot()` renders a stable tree of types, dispositions, content IDs, filenames, and decoded sizes.

Raw MIME is never normalized. Multipart boundaries remain significant in raw and header output.

Artifacts can be saved explicitly:

```php
$message = mailbox()->latest();

$message->saveDiagnostics(storage_path('mailbox/diagnostics.json'), 'json');
$message->saveDiagnostics(storage_path('mailbox/diagnostics.xml'), 'junit');
$message->saveFixture(storage_path('mailbox/message.json'));
$message->saveEml(storage_path('mailbox/message.eml'));
```

`diagnostics()` and `saveDiagnostics` include a rules version, capture ID, namespace, and findings. JUnit output turns error-severity findings into failures for CI. `saveFixture` emits portable redacted JSON without Bcc or execution context. `saveEml` copies the unchanged non-empty raw message.

## Queues

No special Mailable API is required: queued Mailables and Notifications use Laravel's normal mail path. The queue payload namespace propagation described above associates the eventual capture with the originating test, including a real long-running worker.

Use `MAILBOX_NAMESPACE` when coordinating independently launched producers and workers. The value is capture metadata and an isolation key, not an authentication boundary. At-least-once queue duplicates are intentionally kept so retry behaviour remains visible.

## Configuration reference

All package settings live under `mailbox.*` in `config/mailbox.php`.

| Key | Default | Environment variable | Meaning |
|---|---:|---|---|
| `enabled` | `null` | `MAILBOX_ENABLED` | `null` defers to the environment guard; `false` forces off. It can never force production on. |
| `environments` | `['local', 'testing']` | — | Application environments allowed to capture and browse. `production` is always refused. |
| `path` | `_mailbox` | `MAILBOX_PATH` | Route prefix, without a required leading slash. |
| `middleware` | `['web']` | — | Middleware on mailbox routes; keep `web` for CSRF protection on mutations. |
| `storage_path` | `null` | `MAILBOX_STORAGE_PATH` | Private storage root; `null` uses `storage/framework/mailbox`. |
| `namespace` | `null` | `MAILBOX_NAMESPACE` | Optional capture namespace used for process and test isolation. |
| `retention.days` | `7` | — | Remove captures older than this many days; `0` disables the age rule. |
| `retention.max_messages` | `1000` | — | Keep at most this many messages. |
| `retention.max_bytes` | `250 * 1024 * 1024` | — | Keep at most 250 MiB across capture records. |
| `limits.raw_bytes` | `50 * 1024 * 1024` | — | Maximum raw message size. |
| `limits.parts` | `100` | — | Maximum extracted MIME parts. |
| `limits.depth` | `30` | — | Maximum structured MIME depth. |
| `limits.header_bytes` | `256 * 1024` | — | Maximum raw header block bytes. |
| `limits.search_text_bytes` | `512 * 1024` | — | Maximum text indexed for search. |
| `limits.preview_bytes` | `2 * 1024 * 1024` | — | Maximum HTML accepted by preview sanitization. |

Changing `.env` does not change a cached configuration. Rebuild or clear Laravel's configuration cache after changing any `MAILBOX_*` value.

## Commands

| Command | Purpose |
|---|---|
| `php artisan mailbox:doctor` | Inspect the guard, mailer composition, middleware, storage, SQLite/schema, caches, retention, and orphan state. Exits non-zero for critical findings. |
| `php artisan mailbox:doctor --json` | Emit doctor findings as JSON. |
| `php artisan mailbox:doctor --repair` | Remove orphan directories, dangling rows, and stale temporary entries before reporting health. |
| `php artisan mailbox:clear` | Delete every captured message, with confirmation in an interactive shell. |
| `php artisan mailbox:clear --force` | Delete every captured message without confirmation. |
| `php artisan mailbox:prune` | Apply the configured age, message-count, and byte retention limits. |

## Storage and retention

The default private store is `storage/framework/mailbox`:

```text
storage/framework/mailbox/
├── index.sqlite
├── .lock
├── messages/
│   └── {capture-ulid}/
│       ├── raw.eml
│       └── parts/
│           └── {part-ulid}.bin
└── tmp/
```

Opaque ULIDs—not attachment filenames—select files on disk. Directories are created with owner-only permissions where the operating system supports POSIX modes.

Retention is enforced opportunistically after captures and explicitly by `mailbox:prune`. The default policy removes messages older than seven days and keeps at most 1,000 messages or 250 MiB. `mailbox:clear` removes the entire capture set.

## Troubleshooting

Start with:

```bash
php artisan mailbox:doctor
```

- **No messages appear:** confirm the application environment is `local` or `testing`, `MAILBOX_ENABLED` is not false, and `MAIL_MAILER=local`. A named mailer can also be selected explicitly with Laravel's normal `Mail::mailer('local')` API.
- **Environment changes have no effect:** run `php artisan config:clear`, or rebuild with `php artisan config:cache` after changing `.env`.
- **The doctor reports a mailer collision:** an application-defined `mail.mailers.local` takes precedence. Rename it or configure its transport as `local` deliberately.
- **The doctor reports failover or round-robin composition:** remove the local transport from that mailer's `mailers` list. A development capture transport inside failover can accept mail instead of the intended delivery transport.
- **The UI returns 403:** the application defines a `viewMailbox` gate and the current user is not allowed.
- **The UI returns 404 or sends throw:** the fail-closed environment guard is refusing access. Do not bypass it for production.
- **Storage looks inconsistent after a crash:** run `php artisan mailbox:doctor --repair`, then inspect the reported findings.

## Mailpit and Mailtrap Local

Laravel Mailbox is complementary to SMTP-focused tools such as Mailpit and Mailtrap Local. Use those tools when you need an SMTP endpoint or their broader client workflow. Use Laravel Mailbox when you want an embedded Laravel transport, final-output assertions, envelope and original-Bcc facts, test namespaces, and queue-aware diagnostics without a separate service.

You can export any capture as `.eml` from the UI or with `saveEml()` and inspect it in another tool.

## Contributing

See the [contribution guide](.github/CONTRIBUTING.md). Before opening a pull request, run:

```bash
composer validate --strict
composer audit
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
vendor/bin/pest --type-coverage --min=95
```

Please report security issues through the private channel in the [security policy](.github/SECURITY.md), not in a public issue.

## License

Laravel Mailbox is open-source software licensed under the [MIT License](LICENSE.md).
