<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

final class PartController
{
    public function show(string $id, string $part): Response
    {
        return response('', 404);
    }
}
