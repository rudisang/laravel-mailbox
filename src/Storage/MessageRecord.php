<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

/** @internal */
final readonly class MessageRecord
{
    /**
     * @param  'ok'|'partial'|'failed'|'unsupported'  $parseStatus
     * @param  list<array{address: string, name: string}>  $from
     * @param  list<array{address: string, name: string}>  $to
     * @param  list<array{address: string, name: string}>  $cc
     * @param  list<array{address: string, name: string}>  $bcc
     * @param  list<array{address: string, name: string}>  $replyTo
     * @param  list<string>  $envelopeRecipients
     * @param  list<string>  $tags
     * @param  array<string, string>  $metadata
     * @param  list<array{0: string, 1: string}>  $rawHeaders
     * @param  array<string, string|null>  $context
     */
    public function __construct(
        public ?int $seq,
        public string $id,
        public string $capturedAt,
        public ?string $messageId,
        public string $rawSha256,
        public int $rawBytes,
        public ?string $mailer,
        public string $parseStatus,
        public ?string $parseError,
        public ?string $subject,
        public array $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public array $replyTo,
        public ?string $envelopeSender,
        public array $envelopeRecipients,
        public array $tags,
        public array $metadata,
        public array $rawHeaders,
        public bool $hasHtml,
        public bool $hasText,
        public ?string $previewText,
        public ?string $searchText,
        public int $partCount,
        public int $attachmentCount,
        public int $decodedBytes,
        public ?string $readAt,
        public ?string $namespace,
        public array $context,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        /** @var list<array{address: string, name: string}> $from */
        $from = self::decodeJsonArray($row['from_json'] ?? null);
        /** @var list<array{address: string, name: string}> $to */
        $to = self::decodeJsonArray($row['to_json'] ?? null);
        /** @var list<array{address: string, name: string}> $cc */
        $cc = self::decodeJsonArray($row['cc_json'] ?? null);
        /** @var list<array{address: string, name: string}> $bcc */
        $bcc = self::decodeJsonArray($row['bcc_json'] ?? null);
        /** @var list<array{address: string, name: string}> $replyTo */
        $replyTo = self::decodeJsonArray($row['reply_to_json'] ?? null);
        /** @var list<string> $envelopeRecipients */
        $envelopeRecipients = self::decodeJsonArray($row['envelope_recipients_json'] ?? null);
        /** @var list<string> $tags */
        $tags = self::decodeJsonArray($row['tags_json'] ?? null);
        /** @var array<string, string> $metadata */
        $metadata = self::decodeJsonArray($row['metadata_json'] ?? null);
        /** @var list<array{0: string, 1: string}> $rawHeaders */
        $rawHeaders = self::decodeJsonArray($row['raw_headers_json'] ?? null);
        /** @var array<string, string|null> $context */
        $context = self::decodeJsonArray($row['context_json'] ?? null);

        /** @var 'ok'|'partial'|'failed'|'unsupported' $parseStatus */
        $parseStatus = (string) ($row['parse_status'] ?? 'ok');

        return new self(
            isset($row['seq']) ? (int) $row['seq'] : null,
            (string) $row['id'],
            (string) $row['captured_at'],
            isset($row['message_id']) ? (string) $row['message_id'] : null,
            (string) $row['raw_sha256'],
            (int) $row['raw_bytes'],
            isset($row['mailer']) ? (string) $row['mailer'] : null,
            $parseStatus,
            isset($row['parse_error']) ? (string) $row['parse_error'] : null,
            isset($row['subject']) ? (string) $row['subject'] : null,
            $from,
            $to,
            $cc,
            $bcc,
            $replyTo,
            isset($row['envelope_sender']) ? (string) $row['envelope_sender'] : null,
            $envelopeRecipients,
            $tags,
            $metadata,
            $rawHeaders,
            (bool) ($row['has_html'] ?? false),
            (bool) ($row['has_text'] ?? false),
            isset($row['preview_text']) ? (string) $row['preview_text'] : null,
            isset($row['search_text']) ? (string) $row['search_text'] : null,
            (int) ($row['part_count'] ?? 0),
            (int) ($row['attachment_count'] ?? 0),
            (int) ($row['decoded_bytes'] ?? 0),
            isset($row['read_at']) ? (string) $row['read_at'] : null,
            isset($row['namespace']) ? (string) $row['namespace'] : null,
            $context,
        );
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function withSeq(int $seq): self
    {
        return new self(
            $seq,
            $this->id,
            $this->capturedAt,
            $this->messageId,
            $this->rawSha256,
            $this->rawBytes,
            $this->mailer,
            $this->parseStatus,
            $this->parseError,
            $this->subject,
            $this->from,
            $this->to,
            $this->cc,
            $this->bcc,
            $this->replyTo,
            $this->envelopeSender,
            $this->envelopeRecipients,
            $this->tags,
            $this->metadata,
            $this->rawHeaders,
            $this->hasHtml,
            $this->hasText,
            $this->previewText,
            $this->searchText,
            $this->partCount,
            $this->attachmentCount,
            $this->decodedBytes,
            $this->readAt,
            $this->namespace,
            $this->context,
        );
    }

    /** @return array<string, mixed> column => value, for INSERT */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'schema_version' => Schema::version(),
            'captured_at' => $this->capturedAt,
            'message_id' => $this->messageId,
            'raw_sha256' => $this->rawSha256,
            'raw_bytes' => $this->rawBytes,
            'mailer' => $this->mailer,
            'parse_status' => $this->parseStatus,
            'parse_error' => $this->parseError,
            'subject' => $this->subject,
            'from_json' => self::encodeJson($this->from, '[]'),
            'to_json' => self::encodeJson($this->to, '[]'),
            'cc_json' => self::encodeJson($this->cc, '[]'),
            'bcc_json' => self::encodeJson($this->bcc, '[]'),
            'reply_to_json' => self::encodeJson($this->replyTo, '[]'),
            'envelope_sender' => $this->envelopeSender,
            'envelope_recipients_json' => self::encodeJson($this->envelopeRecipients, '[]'),
            'tags_json' => self::encodeJson($this->tags, '[]'),
            'metadata_json' => self::encodeJson($this->metadata, '{}', JSON_FORCE_OBJECT),
            'raw_headers_json' => self::encodeJson($this->rawHeaders, '[]'),
            'has_html' => (int) $this->hasHtml,
            'has_text' => (int) $this->hasText,
            'preview_text' => $this->previewText,
            'search_text' => $this->searchText,
            'part_count' => $this->partCount,
            'attachment_count' => $this->attachmentCount,
            'decoded_bytes' => $this->decodedBytes,
            'read_at' => $this->readAt,
            'namespace' => $this->namespace,
            'context_json' => self::encodeJson($this->context, '{}', JSON_FORCE_OBJECT),
        ];
    }

    /** @return array<mixed> */
    private static function decodeJsonArray(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function encodeJson(mixed $value, string $fallback, int $flags = 0): string
    {
        $encoded = json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($encoded) ? $encoded : $fallback;
    }
}
