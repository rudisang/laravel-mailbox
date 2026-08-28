<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rudisang\Mailbox\Http\InboxFilters;
use Rudisang\Mailbox\Http\MessagePresenter;
use Rudisang\Mailbox\Http\Routing;
use Rudisang\Mailbox\Storage\MaintenanceLock;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\StoragePaths;
use Symfony\Component\HttpFoundation\Response;

final class MessageController
{
    public function __construct(
        private readonly MessageStore $store,
        private readonly MessagePresenter $presenter,
        private readonly StoragePaths $paths,
        private readonly MaintenanceLock $lock,
    ) {}

    public function show(Request $request, string $id): View|Response
    {
        $this->store->markRead($id, true);
        $detail = $this->presenter->detail($id);

        if ($detail === null) {
            return response()->view('mailbox::errors.404', [], 404);
        }

        $raw = $this->rawPreview($id);

        if ($request->query('partial') === 'detail') {
            return view()->make('mailbox::partials.detail', ['detail' => $detail, 'raw' => $raw]);
        }

        $filters = InboxFilters::fromRequest($request)->toArray();

        return view()->make('mailbox::inbox', [
            'messages' => $this->store->list($filters + ['limit' => 100]),
            'filters' => $filters,
            'status' => $this->store->status(null),
            'detail' => $detail,
            'raw' => $raw,
            'basePath' => Routing::basePath(),
            'selectedId' => $id,
        ]);
    }

    public function raw(Request $request, string $id): Response
    {
        if ($this->store->find($id) === null) {
            abort(404);
        }

        $path = $this->paths->raw($id);

        if (is_link($path) || ! is_file($path)) {
            abort(404);
        }

        $size = filesize($path);

        if ($size === false) {
            abort(404);
        }

        $headers = [
            'Content-Type' => 'text/plain; charset=us-ascii',
            'Content-Length' => (string) $size,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ];

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename='.$id.'.eml';
        }

        return response()->stream(static function () use ($path): void {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            fpassthru($handle);
            fclose($handle);
        }, 200, $headers);
    }

    public function read(Request $request, string $id): JsonResponse|RedirectResponse
    {
        if ($this->store->find($id) === null) {
            abort(404);
        }

        $value = $request->input('read');
        $read = is_array($value) || is_object($value)
            ? null
            : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($read === null) {
            abort(422);
        }

        $this->store->markRead($id, $read);

        if ($request->expectsJson()) {
            return response()->json(['read' => $read]);
        }

        return redirect()->route('mailbox.message', $id);
    }

    public function destroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        if ($this->store->find($id) === null) {
            abort(404);
        }

        // Schema initialization takes the same lock, so complete it before maintenance.
        $this->store->pdo();

        $this->lock->exclusive(function () use ($id): void {
            $this->store->delete($id);
        }, true);

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route('mailbox.inbox');
    }

    private function rawPreview(string $id): string
    {
        $path = $this->paths->raw($id);

        if (is_link($path) || ! is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path, false, null, 0, 256 * 1024);

        return $contents === false ? '' : mb_convert_encoding($contents, 'UTF-8', 'UTF-8');
    }
}
