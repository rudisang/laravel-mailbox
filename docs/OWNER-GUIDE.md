# Owner guide — testing Laravel Mailbox locally and publishing it

Package: `rudisang/laravel-mailbox` · namespace `Rudisang\Mailbox` · transport `local` · UI `/_mailbox`
Repository on disk: `~/Herd/laravel-mailbox` (branch `build/v0.1`, all work committed; no remote yet).

## 1. Try it inside one of your Herd apps (Composer path repository)

Herd sites live side by side under `~/Herd`, so the package can be linked with a relative path. Composer symlinks it, so edits to the package show up in the app immediately.

```bash
cd ~/Herd/<your-app>                       # e.g. ~/Herd/desk

# 1) point Composer at the package checkout
composer config repositories.mailbox path ../laravel-mailbox

# 2) require it as a dev dependency (the @dev constraint is needed for an untagged path repo)
composer require --dev "rudisang/laravel-mailbox:@dev"

# 3) select the transport
#    add to .env:
#    MAIL_MAILER=local

# 4) check the wiring
php artisan config:clear && php artisan mailbox:doctor
```

Then open `https://<your-app>.test/_mailbox` and send mail from the app, for example:

```bash
php artisan tinker
>>> Mail::raw('hello from tinker', fn ($m) => $m->to('you@example.com')->subject('Mailbox smoke test'));
```

Useful checks while you are in there:

- Queued mail: run `php artisan queue:work` from the same app (the worker inherits `MAIL_MAILER=local`; captures from a worker are attributed to the test/process that queued them via the job payload).
- Notifications, Markdown mailables, inline images (`$message->embed(...)`) and attachments all render in the HTML tab; the Diagnostics tab shows what the sanitizer removed.
- Keyboard: `j`/`k` move, `Enter` opens, `/` searches, `[`/`]` switch tabs, `u` toggles unread, `e` deletes, `?` shows help.
- Dark mode: the theme button cycles System → Light → Dark.
- `php artisan mailbox:clear` wipes captures (they contain real tokens/links); `php artisan mailbox:prune` applies retention (7 days / 1,000 messages / 250 MiB by default).
- To customise, `php artisan vendor:publish --tag=mailbox-config` and edit `config/mailbox.php`.

Use it in that app's tests:

```php
use Rudisang\Mailbox\Testing\InteractsWithMailbox;

uses(InteractsWithMailbox::class);          // Pest; or `use InteractsWithMailbox;` in a PHPUnit TestCase

it('sends the welcome mail', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail);

    mailbox()->latest()
        ->assertTo('ada@example.com')
        ->assertSubjectContains('Welcome')
        ->assertHtmlContains('Get started')
        ->assertRawHeaderMissing('Bcc')
        ->assertNoScripts();
});
```

Notes:
- The package refuses to run outside `local`/`testing` (`APP_ENV`), so `mailbox:doctor` will tell you if the app's environment name is different.
- If the app caches config (`php artisan config:cache`), re-run it after changing `.env`; run `php artisan optimize:clear` if routes or views look stale.
- To unlink later: `composer remove rudisang/laravel-mailbox && composer config --unset repositories.mailbox`.

## 2. Exercise the package itself

```bash
cd ~/Herd/laravel-mailbox
composer test                 # Larastan + Pint + type coverage + Pest (172 tests incl. 8-process concurrency, SIGKILL and queue:work suites)
composer serve                # builds the Testbench workbench and serves it; open http://127.0.0.1:8000/demo (or the port it prints)
                              #   /demo/send-all seeds welcome, invoice, newsletter, hostile, unicode, plain, notification, queued
cd tests/Browser && npm install && npx playwright install chromium webkit && npm test   # sandbox, keyboard, responsive, axe — Chromium + WebKit
```

## 3. Publish

### 3a. Pre-flight (run from the package directory)

```bash
git status                              # clean
composer validate --strict
composer audit
composer test
(cd tests/Browser && npm test)
```

Then decide the branch shape. `main` still points at the design commit; `build/v0.1` holds everything (39 commits) and fast-forwards cleanly:

```bash
git checkout main
git merge --ff-only build/v0.1
```

### 3b. GitHub

You are logged in to GitHub CLI as `rudisang`:

```bash
gh repo create rudisang/laravel-mailbox --public --source=. --remote=origin --push
```

The first push runs `.github/workflows/tests.yml`: Laravel 12/13 × PHP 8.2–8.5 (lowest + stable), Windows, the Playwright browser job and the quality job (validate, audit, Pint, Larastan, type coverage). Wait for green before tagging.

Repository settings worth doing once: enable Dependabot (config is committed), set `.github/SECURITY.md`'s private vulnerability reporting under *Security → Policy*, and add release-note labels from `.github/release.yml` if you want generated notes.

### 3c. Tag a release

```bash
git tag -a v0.1.0 -m "v0.1.0"        # use `-s` instead of `-a` if you sign tags
git push origin main --tags
gh release create v0.1.0 --generate-notes
```

Composer resolves versions from tags only — `composer.json` deliberately has no `version` field.

### 3d. Packagist

1. Go to https://packagist.org/packages/submit (log in with the account that owns `rudisang`), paste `https://github.com/rudisang/laravel-mailbox`, and submit.
2. Enable auto-updates: on the package page, follow *"Hook not set up"* — the simplest route is authorising the Packagist GitHub App, otherwise add the webhook URL + your Packagist API token under the repository's *Settings → Webhooks*.
3. Verify from any app: `composer require --dev rudisang/laravel-mailbox` (no path repository needed any more).

### 3e. After publishing

- Follow SemVer from `v0.1.0`; the public API is exactly: `config/mailbox.php`, the three commands (`mailbox:doctor|clear|prune`), `Rudisang\Mailbox\Events\MessageCaptured`, `Rudisang\Mailbox\Mailbox::context()/redactContextUsing()`, `Rudisang\Mailbox\Testing\{InteractsWithMailbox, MailboxTester, CapturedMessage}` and the global `mailbox()` helper. Everything else is `@internal`.
- Keep `symfony/html-sanitizer` at or above the patched floor (`^7.4.13 || ^8.0.13`); `composer audit` runs in CI.
- Update `CHANGELOG.md` per release; `UPGRADE.md` for breaking changes.

## 4. Things to know (decisions made on your behalf during the build)

- **No production use, ever:** the transport throws and the routes return 404 outside `local`/`testing` (case-insensitive); `MAILBOX_ENABLED` can only disable, never enable elsewhere.
- **CSS is contained, not sanitized:** email HTML is sanitized (scripts, forms, iframes, every author URL removed) and rendered in an empty-token `sandbox` iframe with a per-message CSP; CSS survives verbatim so previews look right, and the CSP blocks every `url()`/`@import` fetch. Verified in Chromium and WebKit.
- **Links are neutralised in the preview** and listed in the *Links* tab, where only `http/https/mailto` links get an *Open* button.
- **Captures contain secrets** (reset links, tokens). Storage is `storage/framework/mailbox` (0700), pruned after 7 days / 1,000 messages / 250 MiB.
- **Queued duplicates are kept** (at-least-once delivery is a real fact worth seeing), never de-duplicated by Message-ID.
- **Bcc** is stored as protected metadata and never appears in the raw `.eml`, exports or search.
- **No Gmail/Outlook/Apple Mail emulation** — viewport presets are browser previews, not client renderings.
- The plans, specs, review ledgers and every ruling are under `docs/superpowers/` and `.superpowers/sdd/` (the latter is git-ignored).
