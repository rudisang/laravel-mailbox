<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

final readonly class ExtractedPart
{
    public function __construct(
        public string $id,
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
        public ?string $blobPath,
    ) {}
}
