<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

final class Schema
{
    /** @return list<string> */
    public static function statements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS mailbox_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS messages (
                seq INTEGER PRIMARY KEY AUTOINCREMENT,
                id TEXT NOT NULL UNIQUE,
                schema_version INTEGER NOT NULL,
                captured_at TEXT NOT NULL,
                message_id TEXT NULL,
                raw_sha256 TEXT NOT NULL,
                raw_bytes INTEGER NOT NULL,
                mailer TEXT NULL,
                parse_status TEXT NOT NULL,
                parse_error TEXT NULL,
                subject TEXT NULL,
                from_json TEXT NOT NULL,
                to_json TEXT NOT NULL,
                cc_json TEXT NOT NULL,
                bcc_json TEXT NOT NULL,
                reply_to_json TEXT NOT NULL,
                envelope_sender TEXT NULL,
                envelope_recipients_json TEXT NOT NULL,
                tags_json TEXT NOT NULL,
                metadata_json TEXT NOT NULL,
                raw_headers_json TEXT NOT NULL,
                has_html INTEGER NOT NULL,
                has_text INTEGER NOT NULL,
                preview_text TEXT NULL,
                search_text TEXT NULL,
                part_count INTEGER NOT NULL,
                attachment_count INTEGER NOT NULL,
                decoded_bytes INTEGER NOT NULL,
                read_at TEXT NULL,
                namespace TEXT NULL,
                context_json TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS messages_captured_at ON messages (captured_at)',
            'CREATE INDEX IF NOT EXISTS messages_namespace_seq ON messages (namespace, seq)',
            'CREATE INDEX IF NOT EXISTS messages_message_id ON messages (message_id)',
            'CREATE TABLE IF NOT EXISTS parts (
                id TEXT PRIMARY KEY,
                message_id TEXT NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
                parent_id TEXT NULL,
                position INTEGER NOT NULL,
                depth INTEGER NOT NULL,
                content_type TEXT NOT NULL,
                media_type TEXT NOT NULL,
                media_subtype TEXT NOT NULL,
                disposition TEXT NULL,
                filename TEXT NULL,
                content_id TEXT NULL,
                charset TEXT NULL,
                transfer_encoding TEXT NULL,
                decoded_bytes INTEGER NOT NULL,
                sha256 TEXT NULL,
                is_inline INTEGER NOT NULL,
                is_attachment INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS parts_message ON parts (message_id, position)',
            "INSERT OR IGNORE INTO mailbox_meta (key, value) VALUES ('schema_version', '".self::version()."')",
        ];
    }

    public static function version(): int
    {
        return 1;
    }

    /** @return list<string> */
    public static function dropStatements(): array
    {
        return [
            'DROP TABLE IF EXISTS parts',
            'DROP TABLE IF EXISTS messages',
            'DROP TABLE IF EXISTS mailbox_meta',
        ];
    }
}
