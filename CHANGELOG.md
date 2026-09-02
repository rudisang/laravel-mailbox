# Changelog

All notable changes to `rudisang/laravel-mailbox` will be documented in this file.

## Unreleased

Nothing yet.

## v0.2.0 - 2026-09-02

- Mailbox UI: added opt-in browser notifications for newly captured mail. A toolbar bell asks for browser permission on click, remembers the choice per browser, explains how to re-allow blocked notifications, and points plain `http://` sites at HTTPS or `localhost`. Notifications show the subject and sender and open the message when clicked; background polling tightens to five seconds while they are on.
- Mailbox UI: the Message-ID in the message header is shortened to fit and copies its full value on click.
- Status endpoint: `GET /api/status?since=<seq>` now includes `arrived` and up to five `recent` previews when mail was captured after the given sequence. Existing keys and ETag behaviour are unchanged.

## v0.1.0 - 2026-09-02

- Capture kernel: added the fail-closed local transport, crash-safe exact raw MIME capture, envelope and original-recipient preservation, bounded structured extraction, SQLite index, retention, repair, and concurrency support.
- Mailbox UI: added the responsive accessible inbox, secure sandboxed HTML and text previews, headers/envelope/MIME/raw/attachment/link views, diagnostics, search, filters, themes, viewport presets, and keyboard navigation.
- Testing API: added namespace-isolated final-output queries and assertions, queue payload propagation for real workers, high-water capture waits, field-aware snapshots, fixtures, and unchanged `.eml` export.
- Diagnostics: added versioned compatibility and security findings plus JSON and JUnit artifact writers for CI.
