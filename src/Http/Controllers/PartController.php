<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Rudisang\Mailbox\Security\AttachmentPolicy;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\StoragePaths;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PartController
{
    public function __construct(
        private readonly MessageStore $store,
        private readonly StoragePaths $paths,
        private readonly AttachmentPolicy $policy,
    ) {}

    public function show(string $id, string $part): StreamedResponse
    {
        $record = $this->store->findPart($id, $part);

        if ($record === null) {
            abort(404);
        }

        $path = $this->paths->part($id, $part);

        if (is_link($path) || ! is_file($path)) {
            abort(404);
        }

        $size = filesize($path);

        if ($size === false) {
            abort(404);
        }

        $inlineType = $this->policy->inlineType($path);
        $filename = $this->policy->safeFilename($record->filename, 'part-'.$record->position.'.bin');
        $headers = [
            'Content-Type' => $inlineType ?? 'application/octet-stream',
            'Content-Disposition' => $inlineType !== null ? 'inline' : $this->policy->disposition($filename),
            'Content-Length' => (string) $size,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        return response()->stream(static function () use ($path): void {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            fpassthru($handle);
            fclose($handle);
        }, 200, $headers);
    }
}
