<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\MessageStore;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
final class StatusController
{
    public function __construct(private readonly MessageStore $store) {}

    public function show(Request $request): JsonResponse|Response
    {
        $status = $this->store->status(null);
        $since = $this->since($request->query('since'));
        $etag = '"'.$status['seq'].'-'.$status['total'].'-'.$status['unread'].($since === null ? '' : '-'.$since).'"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'no-cache',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response()->json($status + $this->recent($since, $status['seq']), 200, $headers);
    }

    private function since(mixed $value): ?int
    {
        if (! is_string($value) || $value === '' || strlen($value) > 18 || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Previews of what arrived after the client's last known sequence, so the UI can
     * announce new mail (toast, browser notification) without scraping the list. Both
     * queries are bounded by the status snapshot's seq, so nothing is reported twice.
     *
     * @return array{}|array{arrived: int, recent: list<array{seq: int, id: string, subject: string|null, from: array{address: string, name: string}|null}>}
     */
    private function recent(?int $since, int $seq): array
    {
        if ($since === null || $since >= $seq) {
            return [];
        }

        return [
            'arrived' => $this->store->countBetween($since, $seq),
            'recent' => array_map(static fn (MessageRecord $record): array => [
                'seq' => $record->seq ?? 0,
                'id' => $record->id,
                'subject' => $record->subject,
                'from' => $record->from[0] ?? null,
            ], $this->store->between($since, $seq)),
        ];
    }
}
