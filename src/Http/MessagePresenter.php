<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http;

use Rudisang\Mailbox\Mime\Charset;
use Rudisang\Mailbox\Security\AttachmentPolicy;
use Rudisang\Mailbox\Security\Diagnostics;
use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
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
        private readonly HtmlPreviewSanitizer $sanitizer,
        private readonly AttachmentPolicy $policy,
    ) {}

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
        $cidMap = [];
        $htmlBytes = null;

        foreach ($parts as $part) {
            $mimeTree[] = [
                'depth' => $part->depth,
                'label' => $this->partLabel($part),
                'part' => $part,
            ];

            if ($part->contentId !== null && $part->contentId !== '') {
                $cidMap[$part->contentId] = $part->id;
            }

            if ($part->mediaType === 'text' && $part->mediaSubtype === 'html' && ! $part->isAttachment && $part->isLeaf()) {
                $htmlBytes ??= $part->decodedBytes;
            }

            if (! $part->isAttachment && ! $part->isInline) {
                continue;
            }

            $attachments[] = [
                'part' => $part,
                'url' => route('mailbox.part', ['id' => $record->id, 'part' => $part->id]),
                'filename' => $part->filename ?? 'part-'.$part->position,
                'size' => $part->decodedBytes,
                'inlineable' => $this->inlineable($record, $part),
            ];
        }

        $preview = $html === null
            ? null
            : $this->sanitizer->sanitize(
                $html,
                $cidMap,
                rtrim(route('mailbox.message', ['id' => $record->id]), '/').'/parts',
            );

        return new MessageDetail(
            $record,
            $parts,
            $attachments,
            $text,
            $record->hasHtml && $html !== null,
            $preview === null ? [] : $preview->links,
            Diagnostics::evaluate($record, $parts, $preview, $htmlBytes),
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
        $contents = file_get_contents($path, false, null, 0, max(0, $limits->previewBytes + 1));

        if ($contents === false) {
            return null;
        }

        return Charset::toUtf8($contents, $part->charset);
    }

    private function inlineable(MessageRecord $record, PartRecord $part): bool
    {
        $path = $this->paths->part($record->id, $part->id);

        return is_file($path) && $this->policy->inlineType($path) !== null;
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
