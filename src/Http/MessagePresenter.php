<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http;

use InvalidArgumentException;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\PartRecord;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Support\StoragePaths;

final class MessagePresenter
{
    public function __construct(
        private readonly MessageStore $store,
        private readonly StoragePaths $paths,
        ?object $sanitizer = null,
        ?object $policy = null,
    ) {
        if ($sanitizer !== null && ! method_exists($sanitizer, 'sanitize')) {
            throw new InvalidArgumentException('The preview sanitizer must provide a sanitize method.');
        }

        if ($policy !== null && ! method_exists($policy, 'inlineType')) {
            throw new InvalidArgumentException('The attachment policy must provide an inlineType method.');
        }
    }

    public function detail(string $id): ?MessageDetail
    {
        $record = $this->store->find($id);

        if ($record === null) {
            return null;
        }

        $parts = $this->store->parts($id);
        $attachments = [];
        $mimeTree = [];
        $html = $this->html($record);
        $text = $this->text($record);

        foreach ($parts as $part) {
            $mimeTree[] = [
                'depth' => $part->depth,
                'label' => $this->partLabel($part),
                'part' => $part,
            ];

            if (! $part->isAttachment && ! $part->isInline) {
                continue;
            }

            $attachments[] = [
                'part' => $part,
                'url' => route('mailbox.part', ['id' => $record->id, 'part' => $part->id]),
                'filename' => $part->filename ?? 'part-'.$part->position,
                'size' => $part->decodedBytes,
                'inlineable' => false,
            ];
        }

        return new MessageDetail(
            $record,
            $parts,
            $attachments,
            $text,
            $record->hasHtml && $html !== null,
            [],
            ['rules_version' => '0', 'results' => []],
            $mimeTree,
            [
                'previewHtml' => route('mailbox.preview.html', ['id' => $record->id]),
                'previewText' => route('mailbox.preview.text', ['id' => $record->id]),
                'raw' => route('mailbox.raw', ['id' => $record->id]),
                'download' => route('mailbox.raw', ['id' => $record->id, 'download' => 1]),
                'read' => route('mailbox.read', ['id' => $record->id]),
                'destroy' => route('mailbox.destroy', ['id' => $record->id]),
            ],
        );
    }

    public function html(MessageRecord $record): ?string
    {
        return $this->body($record, 'html');
    }

    public function text(MessageRecord $record): ?string
    {
        return $this->body($record, 'plain');
    }

    private function body(MessageRecord $record, string $subtype): ?string
    {
        $part = $this->part($record, $subtype);

        if ($part === null) {
            return null;
        }

        $path = $this->paths->part($record->id, $part->id);

        if (! is_file($path)) {
            return null;
        }

        $limits = Limits::fromConfig((array) config('mailbox.limits', []));
        $contents = file_get_contents($path, false, null, 0, max(0, $limits->previewBytes));

        if ($contents === false) {
            return null;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'UTF-8');
    }

    private function part(MessageRecord $record, string $subtype): ?PartRecord
    {
        foreach ($this->store->parts($record->id) as $part) {
            if ($part->mediaType === 'text' && $part->mediaSubtype === $subtype && ! $part->isAttachment && $part->isLeaf()) {
                return $part;
            }
        }

        return null;
    }

    private function partLabel(PartRecord $part): string
    {
        $type = $part->mediaType.'/'.$part->mediaSubtype;

        if (! $part->isLeaf()) {
            return $type;
        }

        if ($part->isInline || $part->isAttachment) {
            $disposition = $part->isInline ? 'inline' : 'attachment';
            $filename = $part->filename ?? 'unnamed';

            return $type.' · '.$disposition.' · '.$filename;
        }

        return $type.' ('.$this->formatBytes($part->decodedBytes).')';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
