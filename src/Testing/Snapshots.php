<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Testing;

use Rudisang\Mailbox\Storage\PartRecord;

final class Snapshots
{
    public static function html(CapturedMessage $message): string
    {
        $html = $message->html() ?? '';
        $references = self::contentIdReferences($message);
        $snapshot = preg_replace_callback(
            '/cid:([^\s"\'<>\)]+)/i',
            static function (array $matches) use ($references): string {
                $contentId = rawurldecode($matches[1]);

                if (! isset($references[$contentId])) {
                    return $matches[0];
                }

                return 'cid:part-'.$references[$contentId];
            },
            $html,
        );

        return $snapshot ?? $html;
    }

    public static function text(CapturedMessage $message): string
    {
        return $message->text() ?? '';
    }

    public static function headers(CapturedMessage $message): string
    {
        $lines = [];

        foreach ($message->rawHeaders() as [$name, $value]) {
            if (strcasecmp($name, 'Message-ID') === 0) {
                $value = '<message-id>';
            } elseif (strcasecmp($name, 'Date') === 0) {
                $value = '<date>';
            }

            $lines[] = $name.': '.$value;
        }

        return implode("\n", $lines);
    }

    public static function mimeTree(CapturedMessage $message): string
    {
        $references = self::contentIdReferences($message);

        return implode("\n", array_map(
            static fn (PartRecord $part): string => str_repeat('  ', $part->depth).self::part($part, $references),
            $message->parts(),
        ));
    }

    /**
     * @return array{
     *     subject: ?string,
     *     from: list<array{address: string, name: string}>,
     *     to: list<array{address: string, name: string}>,
     *     cc: list<array{address: string, name: string}>,
     *     reply_to: list<array{address: string, name: string}>,
     *     raw_headers: list<array{0: string, 1: string}>,
     *     html: ?string,
     *     text: ?string,
     *     attachments: list<array{filename: ?string, content_type: string, disposition: ?string, content_id: ?string, decoded_bytes: int, sha256: ?string}>,
     *     parse_status: string
     * }
     */
    public static function fixture(CapturedMessage $message): array
    {
        $rawHeaders = array_values(array_filter(
            $message->rawHeaders(),
            static fn (array $header): bool => strcasecmp($header[0], 'Bcc') !== 0,
        ));
        $attachments = array_map(
            static fn (PartRecord $part): array => [
                'filename' => $part->filename,
                'content_type' => $part->mediaType.'/'.$part->mediaSubtype,
                'disposition' => $part->disposition,
                'content_id' => $part->contentId,
                'decoded_bytes' => $part->decodedBytes,
                'sha256' => $part->sha256,
            ],
            $message->attachments(),
        );

        return [
            'subject' => $message->subject(),
            'from' => $message->from(),
            'to' => $message->to(),
            'cc' => $message->cc(),
            'reply_to' => $message->replyTo(),
            'raw_headers' => $rawHeaders,
            'html' => $message->html(),
            'text' => $message->text(),
            'attachments' => $attachments,
            'parse_status' => $message->parseStatus(),
        ];
    }

    /** @param array<string, int> $references */
    private static function part(PartRecord $part, array $references): string
    {
        $description = $part->mediaType.'/'.$part->mediaSubtype;

        if (! $part->isLeaf()) {
            return $description;
        }

        if ($part->isInline) {
            $description .= ' inline';
        } elseif ($part->isAttachment) {
            $description .= ' attachment';
        } elseif ($part->disposition !== null && $part->disposition !== '') {
            $description .= ' '.$part->disposition;
        }

        if ($part->contentId !== null && $part->contentId !== '') {
            $contentId = $part->contentId;

            if (preg_match('/\A[0-9a-f]{32}@symfony\z/i', $contentId) === 1 && isset($references[$contentId])) {
                $contentId = 'part-'.$references[$contentId];
            }

            $description .= ' cid='.$contentId;
        }

        $description .= ' ('.$part->decodedBytes.' B)';

        if ($part->filename !== null && $part->filename !== '') {
            $description .= ' '.$part->filename;
        }

        return $description;
    }

    /** @return array<string, int> */
    private static function contentIdReferences(CapturedMessage $message): array
    {
        $contentIds = [];

        foreach ($message->parts() as $part) {
            if ($part->contentId !== null && $part->contentId !== '') {
                $contentIds[$part->contentId] = true;
            }
        }

        $references = [];
        $next = 1;
        preg_replace_callback(
            '/cid:([^\s"\'<>\)]+)/i',
            static function (array $matches) use (&$references, &$next): string {
                $contentId = rawurldecode($matches[1]);

                if (! isset($references[$contentId])) {
                    $references[$contentId] = $next++;
                }

                return $matches[0];
            },
            $message->html() ?? '',
        );

        foreach ($contentIds as $contentId => $_present) {
            if (! isset($references[$contentId])) {
                $references[$contentId] = $next++;
            }
        }

        return $references;
    }
}
