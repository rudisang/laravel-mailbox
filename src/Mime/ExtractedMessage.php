<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

final readonly class ExtractedMessage
{
    /**
     * @param  list<array{address: string, name: string}>  $from
     * @param  list<array{address: string, name: string}>  $to
     * @param  list<array{address: string, name: string}>  $cc
     * @param  list<array{address: string, name: string}>  $bcc
     * @param  list<array{address: string, name: string}>  $replyTo
     * @param  list<ExtractedPart>  $parts
     * @param  list<string>  $tags
     * @param  array<string, string>  $metadata
     * @param  'ok'|'partial'|'failed'|'unsupported'  $parseStatus
     */
    public function __construct(
        public ?string $subject,
        public array $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public array $replyTo,
        public ?string $htmlPartId,
        public ?string $textPartId,
        public array $parts,
        public array $tags,
        public array $metadata,
        public ?string $previewText,
        public ?string $searchText,
        public string $parseStatus,
        public ?string $parseError,
        public int $attachmentCount,
        public int $decodedBytes,
    ) {}

    public static function unsupported(): self
    {
        return new self(
            null,
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            [],
            [],
            [],
            null,
            null,
            'unsupported',
            null,
            0,
            0,
        );
    }

    public static function failed(string $error): self
    {
        return new self(
            null,
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            [],
            [],
            [],
            null,
            null,
            'failed',
            substr($error, 0, 120),
            0,
            0,
        );
    }
}
