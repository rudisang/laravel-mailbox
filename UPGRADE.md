# Upgrade Guide

## Upgrading within 0.x

Laravel Mailbox follows semantic versioning, but releases before 1.0 may include breaking changes in a minor release. Review [CHANGELOG.md](CHANGELOG.md) before changing the version constraint and run the full test suite against the new version.

Published configuration is copied into the host application and is not updated automatically. Compare your published `config/mailbox.php` with the package version after upgrading, especially for new security, retention, or limit settings. Rebuild Laravel's configuration and route caches after making changes.

The mailbox store contains disposable local-development captures, not application records or a stable external data format. Before an upgrade, export any message that must be retained with the UI or `$message->saveEml()`. If a release notes a storage incompatibility, clear the store with `php artisan mailbox:clear --force` and let the package recreate it.

## v0.1.0

This is the initial release. There are no upgrade steps from an earlier version.
