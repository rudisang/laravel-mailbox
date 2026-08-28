<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Storage;

final readonly class PartRecord
{
    public function __construct(
        public string $id,
        public string $messageId,
        public ?string $parentId,
        public int $position,
        public int $depth,
        public string $contentType,
        public string $mediaType,
        public string $mediaSubtype,
        public ?string $disposition,
        public ?string $filename,
        public ?string $contentId,
        public ?string $charset,
        public ?string $transferEncoding,
        public int $decodedBytes,
        public ?string $sha256,
        public bool $isInline,
        public bool $isAttachment,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['message_id'],
            isset($row['parent_id']) ? (string) $row['parent_id'] : null,
            (int) $row['position'],
            (int) $row['depth'],
            (string) $row['content_type'],
            (string) $row['media_type'],
            (string) $row['media_subtype'],
            isset($row['disposition']) ? (string) $row['disposition'] : null,
            isset($row['filename']) ? (string) $row['filename'] : null,
            isset($row['content_id']) ? (string) $row['content_id'] : null,
            isset($row['charset']) ? (string) $row['charset'] : null,
            isset($row['transfer_encoding']) ? (string) $row['transfer_encoding'] : null,
            (int) $row['decoded_bytes'],
            isset($row['sha256']) ? (string) $row['sha256'] : null,
            (bool) ($row['is_inline'] ?? false),
            (bool) ($row['is_attachment'] ?? false),
        );
    }

    public function isLeaf(): bool
    {
        return $this->mediaType !== 'multipart';
    }

    /** @return array<string, mixed> column => value, for INSERT */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->messageId,
            'parent_id' => $this->parentId,
            'position' => $this->position,
            'depth' => $this->depth,
            'content_type' => $this->contentType,
            'media_type' => $this->mediaType,
            'media_subtype' => $this->mediaSubtype,
            'disposition' => $this->disposition,
            'filename' => $this->filename,
            'content_id' => $this->contentId,
            'charset' => $this->charset,
            'transfer_encoding' => $this->transferEncoding,
            'decoded_bytes' => $this->decodedBytes,
            'sha256' => $this->sha256,
            'is_inline' => (int) $this->isInline,
            'is_attachment' => (int) $this->isAttachment,
        ];
    }
}
