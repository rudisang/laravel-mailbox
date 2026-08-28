<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rudisang\Mailbox\Storage\MessageStore;

final class InboxController
{
    public function __construct(private readonly MessageStore $store) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $messages = $this->store->list($filters + ['limit' => 100]);

        if ($request->query('partial') === 'list') {
            return view()->make('mailbox::partials.list', [
                'messages' => $messages,
                'filters' => $filters,
                'selectedId' => null,
            ]);
        }

        return view()->make('mailbox::inbox', [
            'messages' => $messages,
            'filters' => $filters,
            'status' => $this->store->status(null),
            'detail' => null,
            'basePath' => url(trim((string) config('mailbox.path', '_mailbox'), '/')),
            'selectedId' => null,
        ]);
    }

    public function clear(Request $request): JsonResponse|RedirectResponse
    {
        $this->store->clear();

        if ($request->expectsJson()) {
            return response()->json(['cleared' => true]);
        }

        return redirect()->route('mailbox.inbox');
    }

    /** @return array{q: string, unread: bool, attachments: bool, issues: bool} */
    private function filters(Request $request): array
    {
        $query = $request->query('q');
        $query = is_string($query) ? mb_substr(trim($query), 0, 200) : '';

        return [
            'q' => $query,
            'unread' => $this->boolean($request->query('unread')),
            'attachments' => $this->boolean($request->query('attachments')),
            'issues' => $this->boolean($request->query('issues')),
        ];
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
