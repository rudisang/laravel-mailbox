<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Rudisang\Mailbox\Support\Assets;
use Symfony\Component\HttpFoundation\Response;

final class AssetController
{
    public function show(string $file): Response
    {
        if (! in_array($file, ['mailbox.css', 'mailbox.js'], true)) {
            abort(404);
        }

        $path = Assets::path($file);
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            abort(404);
        }

        $contentType = $file === 'mailbox.css'
            ? 'text/css; charset=utf-8'
            : 'text/javascript; charset=utf-8';

        return new Response($contents, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.Assets::version($file).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
