<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

final class PreviewController
{
    public function html(string $id): Response
    {
        return response('', 404);
    }

    public function text(string $id): Response
    {
        return response('', 404);
    }
}
