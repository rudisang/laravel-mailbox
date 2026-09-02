# Publishing Laravel Mailbox to Packagist — a first-timer's walkthrough

This is the click-by-click guide for the very first release, `v0.1.0`. Every step links its primary source. Commands run from `~/Herd/laravel-mailbox` unless stated.

The package follows [Semantic Versioning](https://semver.org/). It is **pre-1.0 on purpose**: SemVer says "major version zero is for initial development — anything may change". In `0.x`, a breaking change bumps the minor (`0.2.0`), a fix bumps the patch (`0.1.1`). Do not tag `v1.0.0` until you decide the public API is frozen.

## Step 0 — pre-flight checklist (10 points)

1. `git status` is clean and you are on the branch you will release from.
2. `composer validate --strict` passes and the `name` is `rudisang/laravel-mailbox` (this is what claims the `rudisang` vendor on Packagist).
3. `composer audit` reports no advisories.
4. `composer test` passes (Pint, Larastan, 100 % type coverage, the full Pest suite).
5. `cd tests/Browser && npm test` passes in Chromium and WebKit.
6. `README.md` renders correctly (check locally or on GitHub after pushing) — Packagist displays the README of the repository's **default branch**.
7. `LICENSE.md` exists and `composer.json`'s `license` is the SPDX id `MIT` (it is).
8. `composer.json` has `description`, `keywords`, `homepage`, and a `support` block with `issues` + `source` (it does) — this is what makes the Packagist listing look complete.
9. `CHANGELOG.md` has the `v0.1.0` entry.
10. There is no `version` field in composer.json — correct: Composer derives versions **only from git tags** ([schema docs](https://getcomposer.org/doc/04-schema.md)).

## Step 1 — put the code on GitHub

`main` currently points at the design commit; the finished work is on `build/v0.1` and fast-forwards cleanly:

```bash
git checkout main
git merge --ff-only build/v0.1
```

You are already logged in to the GitHub CLI as `rudisang`, so one command creates the public repo, adds the remote and pushes:

```bash
gh repo create rudisang/laravel-mailbox --public --source=. --remote=origin --push
```

([gh repo create manual](https://cli.github.com/manual/gh_repo_create))

Then, on github.com → the repo:
- **Wait for Actions to go green.** The first run executes four Pest combinations (Laravel 12 on PHP 8.3 with lowest dependencies and PHP 8.4 with stable dependencies; Laravel 13 on PHP 8.3 with lowest dependencies and PHP 8.5 with stable dependencies), a PHP 8.2 no-dev install check, Windows, browser, and quality. The Windows job has never run before — if it needs one fix iteration, do it now, before tagging.
- **About → add topics**: `laravel`, `php`, `mail`, `email-testing`, `developer-tools` ([topics docs](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/classifying-your-repository-with-topics)).
- **Settings → Branches**: protect `main` (require the tests workflow to pass).
- **Settings → Advanced Security / Security → Policy**: enable *private vulnerability reporting* so `.github/SECURITY.md`'s promise works.

## Step 2 — tag the release

Composer needs a **tag**; GitHub Releases are for humans (Packagist only reads tags).

```bash
git tag -a v0.1.0 -m "v0.1.0"
git push origin main --tags
```

Use an **annotated** tag (`-a`) — it is a full git object with author/date/message; use `-s` instead if you have GPG signing configured ([git tagging docs](https://git-scm.com/book/en/v2/Git-Basics-Tagging)). A plain `v0.1.0` tag has **stable** stability in Composer's eyes — stability comes from suffixes like `-beta`, not from the leading `0` ([versions doc](https://getcomposer.org/doc/articles/versions.md)) — so default `minimum-stability: stable` projects can install it.

Create the human-readable release notes:

```bash
gh release create v0.1.0 --generate-notes
```

([gh release create manual](https://cli.github.com/manual/gh_release_create))

## Step 3 — create your Packagist account

1. Go to https://packagist.org and **log in with GitHub** (recommended — it ties ownership to your GitHub identity and lets Packagist set up auto-updating for you). ([packagist.org/about](https://packagist.org/about))
2. That's the whole account setup; there is nothing to verify beyond the GitHub OAuth.

## Step 4 — submit the package

1. Open https://packagist.org/packages/submit.
2. Paste the repository URL: `https://github.com/rudisang/laravel-mailbox`.
3. The form checks the repo and shows the package name it found (from composer.json). Submit.
4. **Vendor claim:** publishing this first package is what claims the `rudisang` vendor name — vendors are protected once a package exists under them ([packagist.org/about](https://packagist.org/about)).
5. Indexing is effectively immediate on submission (the site's search index refreshes about every 5 minutes).

Common first-submission mistakes (none apply here, but so you recognise them): composer.json `name` not matching the URL you expect; no tags pushed (only `dev-main` shows, so `composer require` without a `:@dev` constraint fails on stable-only projects); an invalid SPDX `license` string; a README that doesn't render because it isn't on the default branch.

## Step 5 — enable auto-updating

So each future `git push --tags` shows up on Packagist immediately (instead of waiting for the weekly re-crawl):

- **Easiest:** on https://packagist.org/profile/edit make sure your account shows **Connected to GitHub**. Packagist then configures the update hook on your repos itself.
- **Manual fallback** (if you prefer not to grant the OAuth scope): GitHub repo → Settings → Webhooks → *Add webhook* — Payload URL `https://packagist.org/api/github?username=rudisang`, content type `application/json`, secret = your Packagist **API token** (profile → "Show API Token"), events: just *push*.

([packagist.org/about](https://packagist.org/about) documents both.)

## Step 6 — verify like a stranger would

In a scratch directory (NOT one of your apps with the path repository configured):

```bash
mkdir /tmp/mailbox-check && cd /tmp/mailbox-check
composer init --no-interaction --name tmp/check --require-dev "rudisang/laravel-mailbox:^0.1"
composer install
composer show rudisang/laravel-mailbox   # should print v0.1.0 from Packagist
```

Also open https://packagist.org/packages/rudisang/laravel-mailbox and check: version list shows `v0.1.0`, README renders with images, license/keywords/support links present.

Finally, in one of your Herd apps that used the path repository, switch to the real package:

```bash
composer remove rudisang/laravel-mailbox
composer config --unset repositories.mailbox
composer require --dev "rudisang/laravel-mailbox:^0.1"
```

## Step 7 — every release after this one

1. Merge the work to `main`; CI green.
2. Bump per SemVer: fix-only release → patch; any new backward-compatible feature or breaking change while pre-1.0 → minor.
3. Update `CHANGELOG.md` manually (heading `## vX.Y.Z - YYYY-MM-DD`) before tagging, and update `UPGRADE.md` if anything breaks.
4. `git tag -a vX.Y.Z -m "vX.Y.Z" && git push origin main --tags && gh release create vX.Y.Z --generate-notes`.
5. Packagist updates itself via the hook — verify the new version appears.

Tell your users to require `^0.1` (not `^0`): for `0.x`, Composer's caret pins the minor — `^0.1` means `>=0.1.0 <0.2.0` — so nobody is dragged across a breaking pre-1.0 boundary automatically.
