<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rudisang\Mailbox\Http\MessagePresenter;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\StoragePaths;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MessageController
{
    public function __construct(
        private readonly MessageStore $store,
        private readonly MessagePresenter $presenter,
        private readonly StoragePaths $paths,
    ) {}

    public function show(Request $request, string $id): View
    {
        $detail = $this->presenter->detail($id);

        if ($detail === null) {
            abort(404);
        }

        $this->store->markRead($id, true);
        $raw = $this->rawPreview($id);

        if ($request->query('partial') === 'detail') {
            return view()->make('mailbox::partials.detail', ['detail' => $detail, 'raw' => $raw]);
        }

        $filters = $this->filters($request);

        return view()->make('mailbox::inbox', [
            'messages' => $this->store->list($filters + ['limit' => 100]),
            'filters' => $filters,
            'status' => $this->store->status(null),
            'detail' => $detail,
            'raw' => $raw,
            'basePath' => url(trim((string) config('mailbox.path', '_mailbox'), '/')),
            'selectedId' => $id,
        ]);
    }

    public function raw(Request $request, string $id): StreamedResponse
    {
        if ($this->store->find($id) === null) {
            abort(404);
        }

        $path = $this->paths->raw($id);

        if (! is_file($path)) {
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
            $headers['Content-Disposition'] = 'attachment; filename="'.$id.'.eml"';
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

        return redirect()->back();
    }

    public function destroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        if ($this->store->find($id) === null) {
            abort(404);
        }

        $this->store->delete($id);

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route('mailbox.inbox');
    }

    /** @return array{q: string, unread: bool, attachments: bool, issues: bool} */
    private function filters(Request $request): array
    {
        $query = $request->query('q');

        return [
            'q' => is_string($query) ? mb_substr(trim($query), 0, 200) : '',
            'unread' => $this->boolean($request->query('unread')),
            'attachments' => $this->boolean($request->query('attachments')),
            'issues' => $this->boolean($request->query('issues')),
        ];
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function rawPreview(string $id): string
    {
        $path = $this->paths->raw($id);

        if (! is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path, false, null, 0, 256 * 1024);

        return $contents === false ? '' : mb_convert_encoding($contents, 'UTF-8', 'UTF-8');
    }
}
