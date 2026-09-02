<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rudisang\Mailbox\Http\InboxFilters;
use Rudisang\Mailbox\Http\Routing;
use Rudisang\Mailbox\Storage\MaintenanceLock;
use Rudisang\Mailbox\Storage\MessageStore;

/** @internal */
final class InboxController
{
    public function __construct(
        private readonly MessageStore $store,
        private readonly MaintenanceLock $lock,
    ) {}

    public function index(Request $request): View
    {
        $filters = InboxFilters::fromRequest($request)->toArray();
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
            'basePath' => Routing::basePath(),
            'selectedId' => null,
        ]);
    }

    public function clear(Request $request): JsonResponse|RedirectResponse
    {
        // Schema initialization takes the same lock, so complete it before maintenance.
        $this->store->pdo();

        $cleared = $this->lock->exclusive(function (): bool {
            $this->store->clear();

            return true;
        }, true);

        if ($cleared !== true) {
            abort(503, 'Mailbox maintenance could not acquire the exclusive lock.');
        }

        if ($request->expectsJson()) {
            return response()->json(['cleared' => true]);
        }

        return redirect()->route('mailbox.inbox');
    }
}
