<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http;

use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\PartRecord;

/** @internal */
final readonly class MessageDetail
{
    /**
     * @param  list<PartRecord>  $parts
     * @param  list<array{part: PartRecord, url: string, filename: string, size: int, inlineable: bool}>  $attachments
     * @param  list<array{text: string, url: string, openable: bool}>  $links
     * @param  array<string, mixed>  $diagnostics
     * @param  list<array{depth: int, label: string, part: PartRecord}>  $mimeTree
     * @param  array<string, string>  $urls
     */
    public function __construct(
        public MessageRecord $record,
        public array $parts,
        public array $attachments,
        public ?string $text,
        public bool $hasHtml,
        public array $links,
        public array $diagnostics,
        public array $mimeTree,
        public array $urls,
    ) {}
}
