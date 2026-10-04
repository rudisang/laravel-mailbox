# Upgrade Guide

## Upgrading within 0.x

Laravel Mailbox follows semantic versioning, but releases before 1.0 may include breaking changes in a minor release. Review [CHANGELOG.md](CHANGELOG.md) before changing the version constraint and run the full test suite against the new version.

Published configuration is copied into the host application and is not updated automatically. Compare your published `config/mailbox.php` with the package version after upgrading, especially for new security, retention, or limit settings. Rebuild Laravel's configuration and route caches after making changes.

The mailbox store contains disposable local-development captures, not application records or a stable external data format. Before an upgrade, export any message that must be retained with the UI or `$message->saveEml()`. If a release notes a storage incompatibility, clear the store with `php artisan mailbox:clear --force` and let the package recreate it.

## v0.2.1

No code changes. This release requires `symfony/mime` and `symfony/mailer` 7.4.12+ or 8.0.12+, the first releases with fixes for CVE-2026-45067, CVE-2026-45070, and CVE-2026-45068. Symfony 7.2 and 7.3 are end-of-life and did not receive those fixes.

If your application has older Symfony versions locked, let Composer move them together with the package:

```bash
composer update rudisang/laravel-mailbox --with-all-dependencies
```

Laravel 12 and 13 both allow the patched Symfony versions, so no framework upgrade is needed.

## v0.2.0

No upgrade steps. The status endpoint gained optional `arrived` and `recent` keys that only appear when the UI passes `since`; existing keys are unchanged. Browser notifications are a per-browser opt-in in the mailbox toolbar and need a secure page (`localhost`, `127.0.0.1`, or HTTPS).

## v0.1.0

This is the initial release. There are no upgrade steps from an earlier version.
