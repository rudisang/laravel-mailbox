<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rudisang\Mailbox\Storage\MessageStore;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
final class StatusController
{
    public function __construct(private readonly MessageStore $store) {}

    public function show(Request $request): JsonResponse|Response
    {
        $status = $this->store->status(null);
        $etag = '"'.$status['seq'].'-'.$status['total'].'-'.$status['unread'].'"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'no-cache',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response()->json($status, 200, $headers);
    }
}
