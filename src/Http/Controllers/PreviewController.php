<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Rudisang\Mailbox\Http\MessagePresenter;
use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
use Rudisang\Mailbox\Storage\MessageStore;
use Symfony\Component\HttpFoundation\Response;

final class PreviewController
{
    /** @var non-empty-string */
    private const CONTENT_SECURITY_POLICY = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'none'; connect-src 'none'; form-action 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'; frame-ancestors 'self'; sandbox";

    public function __construct(
        private readonly MessageStore $store,
        private readonly MessagePresenter $presenter,
        private readonly HtmlPreviewSanitizer $sanitizer,
    ) {}

    public function html(string $id): Response
    {
        $record = $this->store->find($id);

        if ($record === null) {
            abort(404);
        }

        $html = $this->presenter->html($record);

        if ($html === null) {
            abort(404);
        }

        $cidMap = [];

        foreach ($this->store->parts($id) as $part) {
            if ($part->contentId !== null && $part->contentId !== '') {
                $cidMap[$part->contentId] = $part->id;
            }
        }

        $base = rtrim(route('mailbox.message', ['id' => $record->id]), '/').'/parts';
        $preview = $this->sanitizer->sanitize($html, $cidMap, $base);

        return response($preview->document, 200, $this->headers());
    }

    public function text(string $id): Response
    {
        $record = $this->store->find($id);

        if ($record === null) {
            abort(404);
        }

        $text = $this->presenter->text($record) ?? $record->previewText ?? '';

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $document = '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:16px;font:14px/1.5 ui-monospace,monospace;white-space:pre-wrap;word-break:break-word}</style></head><body><pre>'.$escaped.'</pre></body></html>';

        return response($document, 200, $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ];
    }
}
