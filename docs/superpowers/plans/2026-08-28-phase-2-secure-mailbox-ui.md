# Phase 2 — Secure Mailbox UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A private, fail-closed, server-rendered mailbox at `/_mailbox` with sandboxed HTML preview, safe attachment handling, diagnostics, and a monochrome, fluid, keyboard-complete UI (light: white with black accents; dark: exact inverse).

**Architecture:** Blade views + one hand-authored stylesheet + one dependency-free ES module served by a package route. Controllers read `MessageStore`/blobs through a `MessagePresenter`. Email HTML is sanitized by `HtmlPreviewSanitizer` and served as a separate document into an empty-token `sandbox` iframe with a `default-src 'none'` CSP; every author-controlled URL is stripped and only validated package part URLs are re-injected. Attachments download as octet-stream unless sniffed as an allowlisted raster image.

**Tech Stack:** Laravel routing/Blade, Symfony HtmlSanitizer ≥ 7.4.13/8.0.13, `ext-dom`, `ext-fileinfo`, vanilla CSS/JS, Playwright (dev-only) for browser tests.

**Spec:** `docs/superpowers/specs/2026-08-28-laravel-mailbox-design.md` §9–§11, §14 and `docs/superpowers/specs/2026-08-28-product-spec.md` §11–§12, §18. Phase 1 plan defines the store/records consumed here.

## Global Constraints

- All Phase 1 global constraints apply (strict types, PHP 8.2, Laravel 12+13, no named args on framework calls, Pint + PHPStan clean, conventional commits with the `-c user.name=rudisang -c user.email=rk.morake18@gmail.com` flags).
- Email HTML never enters the workbench DOM. Only the sanitized preview document contains email markup, and only inside `<iframe sandbox>`.
- No CDN, no web fonts, no third-party request, no inline `<script>` in workbench pages (workbench CSP `script-src 'self'`).
- Every action works without JavaScript (links/forms); JavaScript only enhances.
- Asset budget: `mailbox.css` + `mailbox.js` ≤ 250 KiB compressed (target ≤ 40 KiB).
- Route parameter constraint for ids: `[0-9A-HJKMNP-TV-Z]{26}` (`Rudisang\Mailbox\Http\Routing::ULID`).
- Blade output uses `{{ }}` everywhere (never `{!! !!}` for message-derived data).

---

## File map (Phase 2)

| File | Responsibility |
|---|---|
| `routes/web.php` | Route group, names `mailbox.*`, constraints |
| `src/Http/Routing.php` | `ULID` constant; `Routing::url(string $name, array $params)` helper |
| `src/Http/Middleware/Authorize.php` | 404 when guard denies; `viewMailbox` gate when defined |
| `src/Http/Middleware/SecurityHeaders.php` | Workbench CSP, `Referrer-Policy`, `nosniff`, `X-Frame-Options` |
| `src/Http/Controllers/InboxController.php` | `index` (page or list fragment), `clear` |
| `src/Http/Controllers/MessageController.php` | `show` (page or detail fragment; marks read), `raw`, `read`, `destroy` |
| `src/Http/Controllers/PreviewController.php` | `html`, `text` — hardened preview documents |
| `src/Http/Controllers/PartController.php` | inline raster or forced download |
| `src/Http/Controllers/StatusController.php` | `{seq,total,unread}` with ETag |
| `src/Http/Controllers/AssetController.php` | serves `resources/dist/*` immutably |
| `src/Http/MessagePresenter.php`, `src/Http/MessageDetail.php` | Detail view-model assembly |
| `src/Security/HtmlPreviewSanitizer.php`, `src/Security/PreviewResult.php` | §10 pipeline |
| `src/Security/AttachmentPolicy.php` | sniffing, safe filename, disposition |
| `src/Security/Diagnostics.php` | versioned rules → results |
| `src/Support/Assets.php` | `version(string $file): string`, `path(string $file): string` |
| `resources/views/layout.blade.php`, `inbox.blade.php`, `partials/list.blade.php`, `partials/detail.blade.php`, `partials/empty.blade.php`, `partials/tabs/*.blade.php`, `errors/404.blade.php` | UI |
| `resources/dist/mailbox.css`, `resources/dist/mailbox.js` | Design system + behaviour |
| `tests/Browser/` (`package.json`, `playwright.config.ts`, `specs/*.spec.ts`) | Browser suite |

---

### Task 10: Routes, middleware, controllers, minimal views

**Files:**
- Create: `routes/web.php`, `src/Http/Routing.php`, `src/Http/Middleware/Authorize.php`, `src/Http/Middleware/SecurityHeaders.php`, `src/Http/Controllers/InboxController.php`, `src/Http/Controllers/MessageController.php`, `src/Http/Controllers/StatusController.php`, `src/Http/Controllers/AssetController.php`, `src/Http/MessagePresenter.php`, `src/Http/MessageDetail.php`, `src/Support/Assets.php`, `resources/views/layout.blade.php`, `resources/views/inbox.blade.php`, `resources/views/partials/list.blade.php`, `resources/views/partials/detail.blade.php`, `resources/views/partials/empty.blade.php`, `resources/views/errors/404.blade.php`, `resources/dist/mailbox.css` (placeholder: `:root{}`), `resources/dist/mailbox.js` (placeholder: `export {};`)
- Modify: `src/MailboxServiceProvider.php` (`loadRoutesFrom`, `loadViewsFrom('mailbox')`)
- Test: `tests/Feature/Http/RoutesTest.php`

**Interfaces:**
- Route names: `mailbox.inbox`, `mailbox.clear`, `mailbox.status`, `mailbox.asset`, `mailbox.message`, `mailbox.preview.html`, `mailbox.preview.text`, `mailbox.raw`, `mailbox.part`, `mailbox.read`, `mailbox.destroy`.
- `MessagePresenter::__construct(MessageStore $store, StoragePaths $paths, HtmlPreviewSanitizer $sanitizer, AttachmentPolicy $policy)`; `detail(string $id): ?MessageDetail`; `html(MessageRecord $record): ?string` (reads the html leaf blob, capped at `limits.previewBytes`); `text(MessageRecord $record): ?string`.
- `MessageDetail` readonly: `MessageRecord $record`, `list<PartRecord> $parts`, `list<array{part: PartRecord, url: string, filename: string, size: int, inlineable: bool}> $attachments`, `?string $text`, `bool $hasHtml`, `list<array{text:string,url:string,openable:bool}> $links`, `array $diagnostics`, `list<array{depth:int, label:string, part: PartRecord}> $mimeTree`, `array<string,string> $urls` (`previewHtml, previewText, raw, download, read, destroy`).
- Until Task 11 lands, `MessagePresenter` builds `links=[]`, `diagnostics=['rules_version'=>'0','results'=>[]]` — Task 11 wires the real sanitizer/diagnostics.
- Views receive: `inbox` → `messages`, `filters` (`q, unread, attachments, issues`), `status`, `detail` (`?MessageDetail`), `basePath`; `partials.list` → `messages, filters, selectedId`; `partials.detail` → `detail`.
- `GET ?partial=list` returns only the list partial; `GET /messages/{id}?partial=detail` returns only the detail partial; both set `Vary: X-Requested-With`? — no: they key on the query string only.
- `MessageController@show` marks the message read (`markRead($id, true)`) before rendering.
- `StatusController@show`: `?since={seq}` optional; JSON `{seq:int,total:int,unread:int}`; `ETag: "seq-total-unread"`; returns 304 when `If-None-Match` matches; `Cache-Control: no-cache`.
- `AssetController@show(string $file)`: only `mailbox.css`/`mailbox.js`; `Content-Type` `text/css; charset=utf-8` / `text/javascript; charset=utf-8`; `Cache-Control: public, max-age=31536000, immutable`; `ETag`; 404 otherwise.
- `Assets::version('mailbox.css')` = first 12 chars of `md5_file`; `Assets::url('mailbox.css')` = `route('mailbox.asset', ['file' => ...]).'?v='.version`.

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;

function capture(string $subject = 'Route test'): string
{
    Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject($subject)->html('<p>'.$subject.'</p>')->text($subject));

    return app(MessageStore::class)->list()[0]->id;
}

it('serves the inbox with security headers', function () {
    capture('Inbox subject');

    $response = $this->get('/_mailbox');

    $response->assertOk()->assertSee('Inbox subject')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")->toContain("frame-src 'self'")->not->toContain('unsafe-inline');
});

it('returns fragments for partial requests', function () {
    $id = capture('Fragment');

    $this->get('/_mailbox?partial=list')->assertOk()->assertSee('Fragment')->assertDontSee('<html');
    $this->get('/_mailbox/messages/'.$id.'?partial=detail')->assertOk()->assertSee('Fragment')->assertDontSee('<html');
    $this->get('/_mailbox/messages/'.$id)->assertOk()->assertSee('<html', false);
    expect(app(MessageStore::class)->find($id)->isRead())->toBeTrue();
});

it('searches and filters', function () {
    capture('Alpha one');
    capture('Beta two');

    $this->get('/_mailbox?q=beta')->assertSee('Beta two')->assertDontSee('Alpha one');
    $this->get('/_mailbox?unread=1')->assertSee('Alpha one');
});

it('404s outside allowed environments and for unknown or malformed ids', function () {
    $id = capture();
    $this->get('/_mailbox/messages/not-a-ulid')->assertNotFound();
    $this->get('/_mailbox/messages/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();

    $this->app['env'] = 'production';
    $this->get('/_mailbox')->assertNotFound();
    $this->get('/_mailbox/messages/'.$id)->assertNotFound();
});

it('applies the viewMailbox gate when defined', function () {
    Illuminate\Support\Facades\Gate::define('viewMailbox', fn ($user = null) => false);

    $this->get('/_mailbox')->assertForbidden();
});

it('toggles read state, deletes and clears with CSRF protection', function () {
    $id = capture();
    $store = app(MessageStore::class);

    $this->post('/_mailbox/messages/'.$id.'/read', ['read' => false])->assertRedirect();
    expect($store->find($id)->isRead())->toBeFalse();

    $this->postJson('/_mailbox/messages/'.$id.'/read', ['read' => true])->assertOk()->assertJson(['read' => true]);
    $this->delete('/_mailbox/messages/'.$id)->assertRedirect('/_mailbox');
    expect($store->find($id))->toBeNull();

    capture();
    capture();
    $this->post('/_mailbox/clear')->assertRedirect('/_mailbox');
    expect($store->count())->toBe(0);
});

it('rejects mutations without a csrf token when the web middleware is active', function () {
    $id = capture();
    $this->withMiddleware(Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

    $this->delete('/_mailbox/messages/'.$id)->assertStatus(419);
});

it('reports status with etag support', function () {
    capture();
    $first = $this->get('/_mailbox/api/status');
    $first->assertOk()->assertJson(['seq' => 1, 'total' => 1, 'unread' => 1]);
    $etag = $first->headers->get('ETag');

    $this->get('/_mailbox/api/status', ['If-None-Match' => $etag])->assertStatus(304);
    capture();
    $this->get('/_mailbox/api/status', ['If-None-Match' => $etag])->assertOk()->assertJson(['seq' => 2]);
});

it('serves immutable assets and refuses anything else', function () {
    $this->get('/_mailbox/assets/mailbox.css')->assertOk()->assertHeader('Cache-Control', 'max-age=31536000, public, immutable');
    $this->get('/_mailbox/assets/mailbox.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    $this->get('/_mailbox/assets/other.css')->assertNotFound();
    $this->get('/_mailbox/assets/../composer.json')->assertNotFound();
});

it('renders the empty state', function () {
    $this->get('/_mailbox')->assertOk()->assertSee('No messages yet');
});

it('honours a custom path and route caching', function () {
    config()->set('mailbox.path', 'dev/mail');
    (new Rudisang\Mailbox\MailboxServiceProvider($this->app))->boot();

    $this->get('/dev/mail')->assertOk();
})->skip(fn () => true, 'route re-registration in the same app is covered by the config:cache/route:cache test in Phase 3');
```

Laravel's `TestCase` disables CSRF middleware by default (`withoutMiddleware` is not needed; Testbench keeps `VerifyCsrfToken`/`ValidateCsrfToken` but the testing environment excludes it). If the CSRF test cannot force 419 in Testbench, assert instead that the route's middleware list contains `web` (`Route::getRoutes()->getByName('mailbox.destroy')->gatherMiddleware()`).

- [ ] **Step 2: Run tests to verify they fail** — `vendor/bin/pest tests/Feature/Http/RoutesTest.php` → FAIL.

- [ ] **Step 3: Implement**

`routes/web.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rudisang\Mailbox\Http\Controllers\AssetController;
use Rudisang\Mailbox\Http\Controllers\InboxController;
use Rudisang\Mailbox\Http\Controllers\MessageController;
use Rudisang\Mailbox\Http\Controllers\PartController;
use Rudisang\Mailbox\Http\Controllers\PreviewController;
use Rudisang\Mailbox\Http\Controllers\StatusController;
use Rudisang\Mailbox\Http\Middleware\Authorize;
use Rudisang\Mailbox\Http\Middleware\SecurityHeaders;
use Rudisang\Mailbox\Http\Routing;

$middleware = array_values(array_unique(array_merge((array) config('mailbox.middleware', ['web']), [Authorize::class, SecurityHeaders::class])));

Route::group(['prefix' => trim((string) config('mailbox.path', '_mailbox'), '/'), 'as' => 'mailbox.', 'middleware' => $middleware], function (): void {
    Route::get('/', [InboxController::class, 'index'])->name('inbox');
    Route::post('/clear', [InboxController::class, 'clear'])->name('clear');
    Route::get('/api/status', [StatusController::class, 'show'])->name('status');
    Route::get('/assets/{file}', [AssetController::class, 'show'])->where('file', '[a-z]+\.(css|js)')->name('asset');

    Route::prefix('/messages/{id}')->where(['id' => Routing::ULID])->group(function (): void {
        Route::get('/', [MessageController::class, 'show'])->name('message');
        Route::get('/preview/html', [PreviewController::class, 'html'])->name('preview.html');
        Route::get('/preview/text', [PreviewController::class, 'text'])->name('preview.text');
        Route::get('/raw', [MessageController::class, 'raw'])->name('raw');
        Route::get('/parts/{part}', [PartController::class, 'show'])->where('part', Routing::ULID)->name('part');
        Route::post('/read', [MessageController::class, 'read'])->name('read');
        Route::delete('/', [MessageController::class, 'destroy'])->name('destroy');
    });
});
```

`PreviewController` and `PartController` may be stubs in this task (return 404) and are completed in Task 11.

`Authorize`:

```php
public function handle(Request $request, Closure $next): Response
{
    if (! $this->guard->allows()) {
        abort(404);
    }

    if (Gate::has('viewMailbox') && ! Gate::allows('viewMailbox')) {
        abort(403);
    }

    return $next($request);
}
```

`SecurityHeaders`: after `$next`, when the response is HTML **and** the route name is not `mailbox.preview.*`/`mailbox.part`/`mailbox.raw`/`mailbox.asset`, set `Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'self'`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Cache-Control: no-store`.

`InboxController@index`: parse filters (`q` trimmed ≤ 200 chars, booleans), `$messages = $store->list($filters + ['limit' => 100])`, `$status = $store->status(null)`, `$detail = null`; if `partial=list` return `view('mailbox::partials.list', ...)`. `clear`: `$store->clear()`; JSON request → `response()->json(['cleared' => true])`, else redirect to `mailbox.inbox`.

`MessageController@show`: `$detail = $presenter->detail($id) ?? abort(404)`; `markRead`; `partial=detail` → partial view; else full `mailbox::inbox` with `detail`. `raw`: stream file `text/plain; charset=us-ascii`, `nosniff`, `Content-Length`; with `?download=1` use `Content-Disposition: attachment; filename="{id}.eml"`. `read`: body `read` boolean (accept `"0"/"1"/true/false`); JSON → `{read: bool}` else redirect back. `destroy`: delete; JSON → `{deleted: true}` else redirect to inbox.

`MessagePresenter::detail()` assembles `MessageDetail` (attachments with `route('mailbox.part', [...])`, `mimeTree` from parts ordered by position with labels like `multipart/alternative`, `text/html (2.1 KB)`, `image/png · inline · logo.png`), `urls` via `route()`.

Views (Task 10 = semantic, unstyled but complete structure; Task 13 restyles without changing the DOM contract):

- `layout.blade.php`: `<!doctype html><html lang="en" data-theme="system">`, `<head>` with `<meta charset>`, `<meta name="viewport">`, `<meta name="csrf-token" content="{{ csrf_token() }}">`, `<title>Mailbox</title>`, `<link rel="stylesheet" href="{{ Assets::url('mailbox.css') }}">`, `<body data-mailbox-base="{{ url(config('mailbox.path')) }}" data-mailbox-seq="{{ $status['seq'] }}">`, header (`<header class="mb-topbar">` with brand "Mailbox", search form `GET` with input `name="q"`, filter links, unread badge `#mailbox-unread`, clear form `POST`, theme toggle `<button data-theme-toggle>`), `<main class="mb-shell">` containing `@yield('content')`, `<div id="mailbox-live" aria-live="polite" class="mb-visually-hidden"></div>`, `<script type="module" src="{{ Assets::url('mailbox.js') }}"></script>`.
- `inbox.blade.php`: two regions: `<section id="mailbox-list" aria-label="Messages">@include('mailbox::partials.list')</section>` and `<section id="mailbox-detail" aria-label="Message">@if($detail) @include('mailbox::partials.detail') @else @include('mailbox::partials.empty') @endif</section>`.
- `partials/list.blade.php`: `<ol class="mb-list" role="list">` with `<li>` per message → `<a href="{{ route('mailbox.message', $m->id) }}" data-message="{{ $m->id }}" class="mb-row @if(!$m->isRead()) is-unread @endif @if($selectedId === $m->id) is-selected @endif" aria-current>` containing from name/address, subject, preview text, `<time datetime>` formatted, attachment icon when `attachmentCount > 0`, parse badge when `parseStatus !== 'ok'`.
- `partials/detail.blade.php`: header card (subject `<h1>`, from/to/cc/bcc chips, time, size, mailer, namespace, message-id), toolbar (`viewport` segmented buttons `data-viewport="375|768|full"`, mark-unread form `POST read`, download `.eml` link, delete form `DELETE` with `@method('DELETE')`), `<div role="tablist">` buttons `data-tab="html|text|headers|envelope|mime|raw|attachments|links|diagnostics"` (`aria-selected`), panels `<section role="tabpanel" id="tab-html" ...>`: HTML → `<iframe sandbox src="{{ $detail->urls['previewHtml'] }}" title="HTML preview" class="mb-preview" data-preview>` (the `sandbox` attribute is **present with no value**); Text → `<iframe sandbox src="{{ previewText }}">`; Headers → `<table>` of `rawHeaders`; Envelope → sender + recipients + original To/Cc/Bcc + reply-to; MIME → nested list from `mimeTree`; Raw → `<pre>{{ Str::limit(raw, 256KiB) }}</pre>` plus link to full raw; Attachments → list with filename, type, size, download link (and `<img src=part>` thumbnail when `inlineable`); Links → table of text/url + Open anchors when `openable`; Diagnostics → list grouped by severity.
- `partials/empty.blade.php`: "No messages yet" + hint `MAIL_MAILER=local`.

- [ ] **Step 4: Run tests, Pint, PHPStan** — `vendor/bin/pest tests/Feature/Http/RoutesTest.php && vendor/bin/pest && vendor/bin/pint --dirty && vendor/bin/phpstan analyse` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: mailbox routes, middleware, controllers and server-rendered views"`

---

### Task 11: Preview sanitizer, attachment policy, diagnostics

**Files:**
- Create: `src/Security/HtmlPreviewSanitizer.php`, `src/Security/PreviewResult.php`, `src/Security/AttachmentPolicy.php`, `src/Security/Diagnostics.php`, `src/Http/Controllers/PreviewController.php`, `src/Http/Controllers/PartController.php`, `tests/Fixtures/xss/hostile.html`
- Modify: `src/Http/MessagePresenter.php` (links + diagnostics), `src/MailboxServiceProvider.php` (bind `HtmlPreviewSanitizer`, `AttachmentPolicy`)
- Test: `tests/Unit/HtmlPreviewSanitizerTest.php`, `tests/Unit/AttachmentPolicyTest.php`, `tests/Unit/DiagnosticsTest.php`, `tests/Feature/Http/PreviewTest.php`, `tests/Feature/Http/PartsTest.php`

**Interfaces:**
- `HtmlPreviewSanitizer::__construct(Limits $limits)`; `sanitize(string $html, array $cidMap, string $partUrlBase): PreviewResult` where `$cidMap` is `contentId => partUlid` and `$partUrlBase` is the parts route URL without the part segment (e.g. `http://host/_mailbox/messages/{id}/parts`).
- `PreviewResult` readonly: `string $document`, `list<array{text:string,url:string,openable:bool}> $links`, `list<string> $remoteImages`, `int $trackingPixels`, `array<string,int> $removed` (keys: `script, form, iframe, object, embed, base, meta_refresh, event_handlers, javascript_urls, svg, math, style_blocks_kept`), `bool $truncated`.
- `AttachmentPolicy::INLINE_TYPES`, `inlineType(string $path): ?string`, `safeFilename(?string $name, string $fallback): string`, `disposition(string $filename): string`.
- `Diagnostics::RULES_VERSION = '2026.08.1'`; `Diagnostics::evaluate(MessageRecord $record, array $parts, ?PreviewResult $preview, ?int $htmlBytes): array{rules_version:string, capture_id:string, namespace:?string, results:list<array{rule:string, severity:'info'|'warning'|'error', message:string, evidence:mixed}>}`.
- `PreviewController@html`: sanitizer document with the preview headers of spec §10; `@text`: `<!doctype html><html><head><meta charset="utf-8"><style>body{margin:16px;font:14px/1.5 ui-monospace,monospace;white-space:pre-wrap;word-break:break-word}</style></head><body><pre>{escaped}</pre></body></html>` with the same headers.
- `PartController@show`: 404 unless the part belongs to the message and the blob exists; inline when `inlineType()` is non-null (`Content-Type` = sniffed type, `Content-Disposition: inline`), else `application/octet-stream` + `attachment` disposition; always `X-Content-Type-Options: nosniff`, `Content-Length`, `Cache-Control: private, max-age=3600`, `Content-Security-Policy: default-src 'none'; sandbox`.

- [ ] **Step 1: Write the hostile fixture** (`tests/Fixtures/xss/hostile.html`) — copy `workbench/resources/views/mail/hostile.blade.php` verbatim (it contains no Blade directives) and add: `<img srcset="https://evil.example.com/a.png 1x" src="x">`, `<video poster="https://evil.example.com/p.png"></video>`, `<div style="background:url(https://evil.example.com/s.png)">css</div>`, `<a href="/_mailbox/clear">same-origin</a>`, `<img src="/logout">`, `<img src="cid:logo" width="64">`, `<img src="data:image/png;base64,iVBORw0KGgo=">`, `<a href="mailto:x@example.com">mail</a>`, `<a href="&#106;avascript:alert(1)">entity js</a>`, `<style>a > b { color: red } .cls::after { content: "x" }</style>`.

- [ ] **Step 2: Write failing tests**

`tests/Unit/HtmlPreviewSanitizerTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
use Rudisang\Mailbox\Support\Limits;

beforeEach(function () {
    $this->sanitizer = new HtmlPreviewSanitizer(Limits::fromConfig([]));
    $this->hostile = file_get_contents(__DIR__.'/../Fixtures/xss/hostile.html');
    $this->result = $this->sanitizer->sanitize($this->hostile, ['logo' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], 'http://app.test/_mailbox/messages/01ARZ3NDEKTSV4RRFFQ69G5FAW/parts');
});

it('removes every active element and handler', function () {
    $doc = $this->result->document;

    foreach (['<script', '<iframe', '<object', '<embed', '<form', '<base', 'http-equiv', 'onload', 'onerror', 'javascript:', '<svg', '<math', 'evil.example.com', '/logout', '/_mailbox/clear', 'srcset', 'poster'] as $needle) {
        expect(strtolower($doc))->not->toContain(strtolower($needle), "document still contains {$needle}");
    }
    expect($this->result->removed['script'])->toBeGreaterThanOrEqual(2)
        ->and($this->result->removed['form'])->toBe(1)
        ->and($this->result->removed['iframe'])->toBe(1)
        ->and($this->result->removed['meta_refresh'])->toBe(1)
        ->and($this->result->removed['base'])->toBe(1)
        ->and($this->result->removed['event_handlers'])->toBeGreaterThanOrEqual(3)
        ->and($this->result->removed['javascript_urls'])->toBeGreaterThanOrEqual(2);
});

it('keeps safe formatting, inline styles and style blocks verbatim', function () {
    $doc = $this->result->document;

    expect($doc)->toContain('<b>Safe formatting</b>')
        ->toContain('class="ok"')
        ->toContain('a > b { color: red }')
        ->toContain('style="')
        ->and($this->result->removed['style_blocks_kept'])->toBeGreaterThanOrEqual(1);
});

it('re-injects only validated cid part urls and data images', function () {
    $doc = $this->result->document;

    expect($doc)->toContain('src="http://app.test/_mailbox/messages/01ARZ3NDEKTSV4RRFFQ69G5FAW/parts/01ARZ3NDEKTSV4RRFFQ69G5FAV"')
        ->toContain('src="data:image/png;base64,iVBORw0KGgo="')
        ->and(substr_count($doc, 'src="'))->toBe(2);
});

it('neutralises links but reports them, with openable only for http(s)/mailto', function () {
    expect($this->result->document)->not->toContain('href=');
    $urls = array_column($this->result->links, 'url');
    expect($urls)->toContain('https://acme.test/reset-password?token=SECRET123')->toContain('mailto:x@example.com');
    $byUrl = array_column($this->result->links, 'openable', 'url');
    expect($byUrl['https://acme.test/reset-password?token=SECRET123'])->toBeTrue()
        ->and($byUrl['mailto:x@example.com'])->toBeTrue();
    foreach ($this->result->links as $link) {
        if (str_starts_with(strtolower($link['url']), 'javascript')) {
            expect($link['openable'])->toBeFalse();
        }
    }
});

it('reports remote images and tracking pixels', function () {
    expect($this->result->remoteImages)->toContain('https://evil.example.com/pixel.gif')
        ->and($this->result->trackingPixels)->toBeGreaterThanOrEqual(1);
});

it('produces a self-contained document with no scripts even for empty input', function () {
    $r = $this->sanitizer->sanitize('', [], 'http://x/parts');

    expect($r->document)->toStartWith('<!doctype html>')->not->toContain('<script');
});

it('truncates oversized html', function () {
    $sanitizer = new HtmlPreviewSanitizer(Limits::fromConfig(['preview_bytes' => 16 * 1024]));

    $r = $sanitizer->sanitize('<p>'.str_repeat('a', 100 * 1024).'</p>', [], 'http://x/parts');

    expect($r->truncated)->toBeTrue()->and(strlen($r->document))->toBeLessThan(40 * 1024);
});

it('drops unknown cid references and non-image data uris', function () {
    $r = $this->sanitizer->sanitize('<img src="cid:missing"><img src="data:text/html;base64,PHNjcmlwdD4=">', [], 'http://x/parts');

    expect($r->document)->not->toContain('src=');
});
```

`tests/Unit/AttachmentPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Security\AttachmentPolicy;

beforeEach(fn () => $this->policy = new AttachmentPolicy);

it('allows only sniffed raster images inline', function () {
    $png = tempnam(sys_get_temp_dir(), 'mb');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $svg = tempnam(sys_get_temp_dir(), 'mb');
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $fake = tempnam(sys_get_temp_dir(), 'mb');
    file_put_contents($fake, 'not a png');

    expect($this->policy->inlineType($png))->toBe('image/png')
        ->and($this->policy->inlineType($svg))->toBeNull()
        ->and($this->policy->inlineType($fake))->toBeNull();
});

it('builds safe filenames and dispositions', function () {
    expect($this->policy->safeFilename("../../etc/passwd\r\nX-Injected: 1.html", 'part.bin'))->toBe('etcpasswdX-Injected 1.html')
        ->and($this->policy->safeFilename(null, 'part.bin'))->toBe('part.bin')
        ->and($this->policy->safeFilename('', 'part.bin'))->toBe('part.bin')
        ->and(strlen($this->policy->safeFilename(str_repeat('a', 300).'.pdf', 'x')))->toBeLessThanOrEqual(120)
        ->and($this->policy->safeFilename('CON.exe', 'x'))->toBe('CON.exe');
    $disposition = $this->policy->disposition('résumé — 履歴書 🚀.txt');
    expect($disposition)->toStartWith('attachment; filename=')->toContain("filename*=utf-8''r%C3%A9sum%C3%A9")->not->toContain("\n");
});
```

`tests/Unit/DiagnosticsTest.php`:

```php
<?php

declare(strict_types=1);

use Rudisang\Mailbox\Security\Diagnostics;
use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Support\Limits;

it('evaluates versioned rules', function () {
    $record = MessageRecord::fromRow(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'captured_at' => '2026-08-28T00:00:00Z', 'raw_sha256' => str_repeat('a', 64), 'raw_bytes' => 10, 'parse_status' => 'partial', 'parse_error' => 'limit:parts', 'subject' => null, 'from_json' => '[]', 'to_json' => '[]', 'cc_json' => '[]', 'bcc_json' => '[]', 'reply_to_json' => '[]', 'envelope_recipients_json' => '[]', 'tags_json' => '[]', 'metadata_json' => '{}', 'raw_headers_json' => json_encode([['Bcc', 'x@example.com']]), 'has_html' => 1, 'has_text' => 0, 'part_count' => 1, 'attachment_count' => 0, 'decoded_bytes' => 0, 'context_json' => '{}']);
    $preview = (new HtmlPreviewSanitizer(Limits::fromConfig([])))->sanitize(file_get_contents(__DIR__.'/../Fixtures/xss/hostile.html'), [], 'http://x/parts');

    $report = Diagnostics::evaluate($record, [], $preview, 150 * 1024);
    $rules = array_column($report['results'], 'severity', 'rule');

    expect($report['rules_version'])->toBe(Diagnostics::RULES_VERSION)
        ->and($report['capture_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($rules['parse.status'])->toBe('warning')
        ->and($rules['limits.hit'])->toBe('warning')
        ->and($rules['raw.bcc_present'])->toBe('error')
        ->and($rules['html.scripts_removed'])->toBe('warning')
        ->and($rules['html.forms_removed'])->toBe('warning')
        ->and($rules['html.tracking_pixels'])->toBe('warning')
        ->and($rules['html.remote_images'])->toBe('info')
        ->and($rules['html.no_text_alternative'])->toBe('warning')
        ->and($rules['html.gmail_clipping'])->toBe('info')
        ->and($rules['message.no_subject'])->toBe('warning')
        ->and($rules['message.no_recipients'])->toBe('warning')
        ->and($rules['links.neutralised'])->toBe('info');
});

it('returns an empty result list for a clean message', function () {
    $record = MessageRecord::fromRow(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'captured_at' => '2026-08-28T00:00:00Z', 'raw_sha256' => str_repeat('a', 64), 'raw_bytes' => 10, 'parse_status' => 'ok', 'subject' => 'Hi', 'from_json' => '[]', 'to_json' => json_encode([['address' => 'a@b.c', 'name' => '']]), 'cc_json' => '[]', 'bcc_json' => '[]', 'reply_to_json' => '[]', 'envelope_recipients_json' => '[]', 'tags_json' => '[]', 'metadata_json' => '{}', 'raw_headers_json' => '[]', 'has_html' => 1, 'has_text' => 1, 'part_count' => 1, 'attachment_count' => 0, 'decoded_bytes' => 0, 'context_json' => '{}']);
    $preview = (new HtmlPreviewSanitizer(Limits::fromConfig([])))->sanitize('<p>clean</p>', [], 'http://x/parts');

    expect(Diagnostics::evaluate($record, [], $preview, 100)['results'])->toBe([]);
});
```

`tests/Feature/Http/PreviewTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;
use Workbench\App\Mail\HostileMail;

it('serves a sandboxed, csp-protected html preview with cid images resolved to part urls', function () {
    Mail::to('v@example.com')->send(new Workbench\App\Mail\WelcomeMail('Ada'));
    $id = app(MessageStore::class)->list()[0]->id;

    $response = $this->get('/_mailbox/messages/'.$id.'/preview/html');

    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'none'")->toContain("img-src 'self' data:")->toContain('sandbox')->toContain("frame-ancestors 'self'");
    expect($response->getContent())->toContain('/_mailbox/messages/'.$id.'/parts/')->not->toContain('href=');
});

it('neuters hostile mail and lists its links and diagnostics in the workbench', function () {
    Mail::to('v@example.com')->send(new HostileMail);
    $id = app(MessageStore::class)->list()[0]->id;

    $preview = $this->get('/_mailbox/messages/'.$id.'/preview/html');
    expect(strtolower($preview->getContent()))->not->toContain('<script')->not->toContain('onload')->not->toContain('evil.example.com');

    $detail = $this->get('/_mailbox/messages/'.$id.'?partial=detail');
    $detail->assertOk()->assertSee('reset-password?token=SECRET123')->assertSee('html.scripts_removed')->assertDontSee('<script>alert', false);
    $this->get('/_mailbox/messages/'.$id.'/preview/text')->assertOk()->assertHeader('Content-Security-Policy');
});

it('escapes hostile subjects in the workbench dom', function () {
    Mail::to('v@example.com')->send(new HostileMail);

    $this->get('/_mailbox')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});
```

`tests/Feature/Http/PartsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Storage\MessageStore;
use Workbench\App\Mail\HostileMail;
use Workbench\App\Mail\InvoiceMail;

it('downloads attachments as octet-stream with safe dispositions', function () {
    Mail::to('b@example.com')->send(new InvoiceMail(9));
    $store = app(MessageStore::class);
    $id = $store->list()[0]->id;
    $pdf = array_values(array_filter($store->parts($id), fn ($p) => $p->filename === 'invoice-9.pdf'))[0];

    $response = $this->get('/_mailbox/messages/'.$id.'/parts/'.$pdf->id);

    $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=invoice-9.pdf')
        ->and($response->headers->get('Content-Length'))->toBe((string) $pdf->decodedBytes);
});

it('never inlines svg, html or mislabelled images and strips header injection from filenames', function () {
    Mail::to('b@example.com')->send(new HostileMail);
    $store = app(MessageStore::class);
    $id = $store->list()[0]->id;

    foreach ($store->parts($id) as $part) {
        if (! $part->isAttachment) {
            continue;
        }
        $response = $this->get('/_mailbox/messages/'.$id.'/parts/'.$part->id);
        $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->not->toContain("\n")->not->toContain('X-Injected:');
    }
});

it('inlines real inline images and 404s for foreign or unknown parts', function () {
    Mail::to('b@example.com')->send(new Workbench\App\Mail\WelcomeMail('Ada'));
    Mail::to('b@example.com')->send(new InvoiceMail(1));
    $store = app(MessageStore::class);
    [$invoice, $welcome] = $store->list();
    $logo = array_values(array_filter($store->parts($welcome->id), fn ($p) => $p->isInline))[0];

    $this->get('/_mailbox/messages/'.$welcome->id.'/parts/'.$logo->id)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/_mailbox/messages/'.$invoice->id.'/parts/'.$logo->id)->assertNotFound();
    $this->get('/_mailbox/messages/'.$welcome->id.'/parts/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
});

it('serves raw source inline and as a download', function () {
    Mail::to('b@example.com')->send(new InvoiceMail(2));
    $id = app(MessageStore::class)->list()[0]->id;

    $this->get('/_mailbox/messages/'.$id.'/raw')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=us-ascii')->assertSee('Subject: Your invoice #2');
    expect($this->get('/_mailbox/messages/'.$id.'/raw?download=1')->headers->get('Content-Disposition'))->toBe('attachment; filename='.$id.'.eml');
});
```

- [ ] **Step 3: Run tests to verify they fail** — `vendor/bin/pest tests/Unit/HtmlPreviewSanitizerTest.php tests/Unit/AttachmentPolicyTest.php tests/Unit/DiagnosticsTest.php tests/Feature/Http/PreviewTest.php tests/Feature/Http/PartsTest.php` → FAIL.

- [ ] **Step 4: Implement the sanitizer** (follow spec §10 exactly)

```php
final class HtmlPreviewSanitizer
{
    private const STRIP_ATTRIBUTES = ['src', 'srcset', 'poster', 'background', 'lowsrc', 'dynsrc', 'ping', 'action', 'formaction', 'href', 'xlink:href', 'data', 'codebase', 'archive', 'longdesc', 'usemap', 'manifest', 'profile'];
    private const COUNTED_ELEMENTS = ['script', 'form', 'iframe', 'object', 'embed', 'base', 'svg', 'math'];

    public function __construct(private readonly Limits $limits) {}

    public function sanitize(string $html, array $cidMap, string $partUrlBase): PreviewResult
    {
        $truncated = false;
        if (strlen($html) > $this->limits->previewBytes) {
            $html = substr($html, 0, $this->limits->previewBytes);
            $truncated = true;
        }
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        $removed = array_fill_keys([...self::COUNTED_ELEMENTS, 'meta_refresh', 'event_handlers', 'javascript_urls', 'style_blocks_kept'], 0);
        $links = []; $remoteImages = []; $trackingPixels = 0; $styles = [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.($html === '' ? '<p></p>' : $html), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_BIGLINES);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        // 1. style blocks
        foreach (iterator_to_array($xpath->query('//style') ?: []) as $style) {
            $styles[] = $style->textContent;
            $style->parentNode?->removeChild($style);
        }
        $removed['style_blocks_kept'] = count($styles);

        // 2. count + drop active elements (the sanitizer would drop them too; counting happens here)
        foreach (self::COUNTED_ELEMENTS as $tag) {
            foreach (iterator_to_array($xpath->query('//'.$tag) ?: []) as $el) {
                $removed[$tag]++;
                $el->parentNode?->removeChild($el);
            }
        }
        foreach (iterator_to_array($xpath->query('//meta[translate(@http-equiv,"REFSH","refsh")="refresh"]') ?: []) as $meta) {
            $removed['meta_refresh']++;
            $meta->parentNode?->removeChild($meta);
        }

        // 3. attributes
        foreach (iterator_to_array($xpath->query('//*') ?: []) as $el) {
            /** @var \DOMElement $el */
            foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                $name = strtolower($attr->name);
                $value = trim($attr->value);
                if (str_starts_with($name, 'on')) { $removed['event_handlers']++; $el->removeAttribute($attr->name); continue; }
                if (in_array($name, self::STRIP_ATTRIBUTES, true)) {
                    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $scheme = strtolower((string) parse_url(preg_replace('/[\x00-\x20]+/', '', $decoded) ?? '', PHP_URL_SCHEME));
                    if ($scheme === 'javascript' || $scheme === 'vbscript') { $removed['javascript_urls']++; }
                    if ($name === 'href' && strtolower($el->tagName) === 'a') {
                        $links[] = ['text' => trim(preg_replace('/\s+/', ' ', $el->textContent) ?? ''), 'url' => $decoded, 'openable' => in_array($scheme, ['http', 'https', 'mailto'], true) && ! preg_match('/[\x00-\x1f\x7f]/', $decoded)];
                    }
                    if ($name === 'src' && strtolower($el->tagName) === 'img') {
                        if ($scheme === 'cid') {
                            $cid = substr($decoded, 4);
                            if (isset($cidMap[$cid]) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $cidMap[$cid])) { $el->setAttribute('data-mailbox-part', $cidMap[$cid]); }
                        } elseif ($scheme === 'data') {
                            if (preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=\s]{1,700000}$#', $decoded)) { $el->setAttribute('data-mailbox-data', preg_replace('/\s+/', '', $decoded)); }
                        } elseif ($scheme === 'http' || $scheme === 'https') {
                            $remoteImages[] = $decoded;
                            $w = $el->getAttribute('width'); $h = $el->getAttribute('height'); $style = strtolower($el->getAttribute('style'));
                            if (($w !== '' && (int) $w <= 1) || ($h !== '' && (int) $h <= 1) || str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden')) { $trackingPixels++; }
                        }
                    }
                    $el->removeAttribute($attr->name);
                }
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $inner = '';
        if ($body !== null) {
            foreach ($body->childNodes as $child) { $inner .= $dom->saveHTML($child); }
        }

        // 4. sanitizer
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowAttribute('data-mailbox-part', 'img')
            ->allowAttribute('data-mailbox-data', 'img')
            ->allowLinkSchemes([])
            ->allowMediaSchemes([])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->withMaxInputLength($this->limits->previewBytes + 1024);
        $clean = (new HtmlSanitizer($config))->sanitize($inner);

        // 5. post-process: re-inject validated urls
        $out = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $out->loadHTML('<?xml encoding="UTF-8"><body>'.$clean.'</body>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $outXpath = new \DOMXPath($out);
        foreach (iterator_to_array($outXpath->query('//img[@data-mailbox-part]') ?: []) as $img) {
            $part = $img->getAttribute('data-mailbox-part');
            $img->removeAttribute('data-mailbox-part');
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $part) && in_array($part, $cidMap, true)) { $img->setAttribute('src', rtrim($partUrlBase, '/').'/'.$part); }
        }
        foreach (iterator_to_array($outXpath->query('//img[@data-mailbox-data]') ?: []) as $img) {
            $data = $img->getAttribute('data-mailbox-data');
            $img->removeAttribute('data-mailbox-data');
            if (preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$#', $data)) { $img->setAttribute('src', $data); }
        }
        foreach (iterator_to_array($outXpath->query('//a') ?: []) as $a) { $a->setAttribute('title', 'Link neutralised in preview — see the Links tab'); }
        $outBody = $out->getElementsByTagName('body')->item(0);
        $final = '';
        if ($outBody !== null) { foreach ($outBody->childNodes as $child) { $final .= $out->saveHTML($child); } }

        $css = implode("\n", $styles);
        $css = str_replace('</style', '<\/style', $css);
        $document = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>'.$css.'</style></head><body>'.$final.'</body></html>';

        return new PreviewResult($document, $links, array_values(array_unique($remoteImages)), $trackingPixels, $removed, $truncated);
    }
}
```

Notes: use `Symfony\Component\HtmlSanitizer\HtmlSanitizer` and `HtmlSanitizerConfig`. `allowMediaSchemes([])`/`allowLinkSchemes([])` with `allowRelative*(false)` guarantee the sanitizer itself never emits an author URL. Data-image size cap: the `{1,700000}` quantifier bounds base64 length (~512 KiB decoded). Do not use `preg_replace` on HTML; the only regexes here classify a single attribute value.

`AttachmentPolicy`:

```php
final class AttachmentPolicy
{
    public const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];

    public function inlineType(string $path): ?string
    {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) && in_array($type, self::INLINE_TYPES, true) ? $type : null;
    }

    public function safeFilename(?string $name, string $fallback): string
    {
        $name = (string) $name;
        $name = \Normalizer::isNormalized($name) ? $name : (\Normalizer::normalize($name) ?: $name);   // only if ext-intl is loaded; otherwise skip
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $name);
        $name = trim(str_replace(['..'], '', $name), ". \t");
        if ($name === '') { return $fallback; }
        if (mb_strlen($name) > 120) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 120 - ($ext !== '' ? mb_strlen($ext) + 1 : 0)).($ext !== '' ? '.'.$ext : '');
        }

        return $name;
    }

    public function disposition(string $filename): string
    {
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $filename) ?? 'attachment';
        $fallback = str_replace(['"', '\\', '%'], '_', $fallback);

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback !== '' ? $fallback : 'attachment');
    }
}
```

Guard the `Normalizer` use with `class_exists(\Normalizer::class)`. Expected test value for `"../../etc/passwd\r\nX-Injected: 1.html"`: CR/LF removed → `../../etc/passwdX-Injected: 1.html` → `..` and `/` and `:` removed → `etcpasswdX-Injected 1.html`.

`Diagnostics::evaluate()` rules and severities exactly as in the test; messages are short English sentences; evidence carries counts/lists; `html.gmail_clipping` fires when `$htmlBytes > 102 * 1024`. `PreviewController` and `PartController` as in Interfaces. `MessagePresenter::detail()` now calls the sanitizer (when html exists) to fill `links` and passes the `PreviewResult` to `Diagnostics::evaluate()`.

- [ ] **Step 5: Run tests, Pint, PHPStan** — `vendor/bin/pest && vendor/bin/pint --dirty && vendor/bin/phpstan analyse` → PASS.

- [ ] **Step 6: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: sandboxed html preview, attachment policy and diagnostics"`

---

### Task 12: Design system, views and interaction layer (design agent)

**Files:**
- Modify: `resources/dist/mailbox.css`, `resources/dist/mailbox.js`, every file under `resources/views/`
- Test: existing Feature tests must stay green; visual/keyboard acceptance is done in Task 13 and by the orchestrator's Playwright vetting.

**Interfaces (DOM contract that JavaScript and tests rely on — keep these ids/attributes):** `body[data-mailbox-base][data-mailbox-seq]`, `#mailbox-list`, `#mailbox-detail`, `#mailbox-live`, `#mailbox-unread`, `a[data-message]`, `form[data-search]`, `[data-theme-toggle]`, `[role=tablist] button[data-tab]`, `[role=tabpanel][id^=tab-]`, `[data-viewport]`, `iframe[data-preview][sandbox]`, `form[data-action=delete]`, `form[data-action=read]`, `form[data-action=clear]`, `[data-shortcuts-help]`.

- [ ] **Step 1: Design tokens and stylesheet** — implement §11 "Visual direction": tokens on `:root` (light) and `[data-theme="dark"]` plus `@media (prefers-color-scheme: dark)` for `data-theme="system"`; type scale, spacing, radii (16/12/10/999), hairline borders, focus rings, motion tokens with `prefers-reduced-motion`. Components: topbar, search, filter chips, unread badge, list rows (unread dot, selected state, hover), detail header card, address chips, segmented controls (tabs + viewport), pill buttons (primary black / secondary outline / ghost / danger), tables (headers, links), code blocks, nested MIME tree, empty state, toast/live region, keyboard help dialog (`<dialog>`), responsive breakpoint at 960 px (single pane with back link below).

- [ ] **Step 2: JavaScript module** (`resources/dist/mailbox.js`, ES module, no dependencies, ≤ 15 KiB):
  - Boot: read `base`, `seq`, CSRF token; enhance `a[data-message]` to fetch `href + '?partial=detail'`, swap `#mailbox-detail` innerHTML (with a 160 ms crossfade class), `history.pushState`, mark row selected/read, focus the detail heading; handle `popstate`.
  - Poll `base + '/api/status?since=' + seq` every 2 s while `document.visibilityState === 'visible'` (30 s hidden; stop after 10 min without interaction, resume on interaction) with `If-None-Match`; on change, fetch `location.pathname + '?partial=list' + current query`, swap `#mailbox-list`, update `#mailbox-unread`, announce in `#mailbox-live` ("3 new messages").
  - Tabs: `role=tablist` keyboard pattern (Left/Right/Home/End), `aria-selected`, hidden panels, remember last tab in `sessionStorage`.
  - Viewport: set `iframe[data-preview]` width to 375/768/100 % with a centred frame.
  - Theme: cycle `system → light → dark`, persist in `localStorage('mailbox-theme')`, apply on boot before first paint (inline in `<head>` is forbidden by CSP — instead set the attribute from `localStorage` at module start and keep the FOUC minimal by defaulting to `system`).
  - Forms with `data-action`: submit via `fetch` (JSON `Accept`), then refresh list/detail; `delete` selects the next row; `clear` uses `confirm()`.
  - Shortcuts (ignored while typing in inputs): `j/k` move selection, `Enter` open, `/` focus search, `e` delete, `u` toggle unread, `[`/`]` previous/next tab, `?` open help dialog, `Escape` close dialog/back to list on mobile.
  - Everything degrades: without JS all links/forms still work.

- [ ] **Step 3: Blade polish** — restyle with the new classes without breaking the DOM contract; escape all message-derived output; add `<noscript>` hint; format dates as `Today 14:03` / `28 Aug 14:03`, sizes as `12.4 KB`.

- [ ] **Step 4: Verify** — `vendor/bin/pest`, then the orchestrator runs the workbench (`composer serve`), sends `/demo/send-all`, and vets with Playwright: screenshots at 1440/1024/390 widths in light and dark; keyboard-only walkthrough; hostile preview shows no dialogs; network log shows only same-origin `/_mailbox/*` requests; asset sizes printed (`gzip -c resources/dist/mailbox.css | wc -c`).

- [ ] **Step 5: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "feat: mailbox design system and interaction layer"`

---

### Task 13: Browser suite (Playwright) and CI job

**Files:**
- Create: `tests/Browser/package.json`, `tests/Browser/playwright.config.ts`, `tests/Browser/specs/sandbox.spec.ts`, `tests/Browser/specs/keyboard.spec.ts`, `tests/Browser/specs/responsive.spec.ts`, `tests/Browser/specs/a11y.spec.ts`, `tests/Browser/.gitignore` (`node_modules`, `test-results`, `playwright-report`)
- Modify: `.github/workflows/tests.yml` (browser job), `.gitattributes` (`/tests` already export-ignored)

**Interfaces:** the webServer command is `php vendor/bin/testbench serve --host=127.0.0.1 --port=8787` from the package root after `composer build`; the seed URL is `http://127.0.0.1:8787/demo/send-all`.

- [ ] **Step 1: package.json** — `{"private": true, "devDependencies": {"@playwright/test": "^1.55", "@axe-core/playwright": "^4.10"}, "scripts": {"test": "playwright test"}}`.

- [ ] **Step 2: playwright.config.ts** — `projects: chromium, webkit`; `webServer: { command: 'cd ../.. && composer build && php vendor/bin/testbench serve --host=127.0.0.1 --port=8787', url: 'http://127.0.0.1:8787/_mailbox', reuseExistingServer: true, timeout: 120000 }`; `use: { baseURL: 'http://127.0.0.1:8787' }`.

- [ ] **Step 3: sandbox.spec.ts** — visit `/demo/send/hostile`; open the newest message; register `page.on('dialog')` → fail; collect `page.on('request')` → every URL must start with `http://127.0.0.1:8787/_mailbox/`; inside the preview frame assert `frame.evaluate(() => typeof window.alert)` cannot run scripts (evaluate `document.scripts.length === 0`), no `form`, no `iframe`; assert the parent `location` unchanged after clicking a link in the frame; assert `page.frames()[1].url()` still the preview URL.

- [ ] **Step 4: keyboard.spec.ts** — `/demo/send-all`; press `j`, `j`, `Enter` → detail heading focused; `]` moves tab; `?` opens help dialog; `u` toggles unread; `e` deletes and selects next; `/` focuses search.

- [ ] **Step 5: responsive.spec.ts** — screenshots at 1440×900, 1024×768, 390×844 in light and dark (`page.emulateMedia({colorScheme})`) saved to `test-results/`; assert no horizontal scrollbar on body (`scrollWidth <= clientWidth`).

- [ ] **Step 6: a11y.spec.ts** — `new AxeBuilder({ page }).analyze()` on inbox and detail; assert no `serious`/`critical` violations.

- [ ] **Step 7: CI job** — add to `.github/workflows/tests.yml` a `browser` job: PHP 8.4 + Node 22, `composer install`, `cd tests/Browser && npm ci && npx playwright install --with-deps chromium webkit && npm test`.

- [ ] **Step 8: Run locally** — `cd tests/Browser && npm install && npx playwright install chromium webkit && npm test` → PASS.

- [ ] **Step 9: Commit** — `git add -A && git -c user.name=rudisang -c user.email=rk.morake18@gmail.com commit -m "test: playwright browser suite and CI job"`

---

## Self-review

- Spec coverage: §9 routes (Task 10), §10 preview/attachments (Task 11), §11 UI (Task 12), §14 browser (Task 13). Diagnostics rules (§12 list) in Task 11.
- Type consistency: `MessageDetail` fields, `PreviewResult` fields, `Diagnostics::evaluate` signature, `AttachmentPolicy` methods and route names are identical across tasks and reused in Phase 3.
