# Changelog

All notable changes to `rudisang/laravel-mailbox` will be documented in this file.

## Unreleased

Nothing yet.

## v0.2.1 - 2026-10-04

- Security: raised the minimum `symfony/mime` and `symfony/mailer` versions to `^7.4.12 || ^8.0.12`. Older releases allowed by the previous `^7.2 || ^8.0` constraint are affected by [CVE-2026-45067](https://github.com/advisories/GHSA-qpmx-3rfj-7rhv) (header and SMTP command injection through line breaks in `Address`), [CVE-2026-45070](https://github.com/advisories/GHSA-vqc8-7275-q272) (header injection through MIME parameter names), and [CVE-2026-45068](https://github.com/advisories/GHSA-xx3c-qf5g-hc39) (argument injection in `SendmailTransport`). The vulnerable code is in Symfony, not in this package, and no Laravel Mailbox source changed; the package can simply no longer be installed alongside the affected Symfony versions. See [UPGRADE.md](UPGRADE.md#v021) if Composer reports a conflict.

## v0.2.0 - 2026-09-02

- Mailbox UI: added opt-in browser notifications for newly captured mail. A toolbar bell asks for browser permission on click, remembers the choice per browser, explains how to re-allow blocked notifications, and points plain `http://` sites at HTTPS or `localhost`. Notifications show the subject and sender and open the message when clicked; background polling tightens to five seconds while they are on.
- Mailbox UI: the Message-ID in the message header is shortened to fit and copies its full value on click.
- Status endpoint: `GET /api/status?since=<seq>` now includes `arrived` and up to five `recent` previews when mail was captured after the given sequence. Existing keys and ETag behaviour are unchanged.

## v0.1.0 - 2026-09-02

- Capture kernel: added the fail-closed local transport, crash-safe exact raw MIME capture, envelope and original-recipient preservation, bounded structured extraction, SQLite index, retention, repair, and concurrency support.
- Mailbox UI: added the responsive accessible inbox, secure sandboxed HTML and text previews, headers/envelope/MIME/raw/attachment/link views, diagnostics, search, filters, themes, viewport presets, and keyboard navigation.
- Testing API: added namespace-isolated final-output queries and assertions, queue payload propagation for real workers, high-water capture waits, field-aware snapshots, fixtures, and unchanged `.eml` export.
- Diagnostics: added versioned compatibility and security findings plus JSON and JUnit artifact writers for CI.
