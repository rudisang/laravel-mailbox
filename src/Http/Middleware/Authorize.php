<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Rudisang\Mailbox\Support\EnvironmentGuard;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    public function __construct(private readonly EnvironmentGuard $guard) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->guard->allows()) {
            abort(404);
        }

        if (Gate::has('viewMailbox') && ! Gate::allows('viewMailbox')) {
            abort(403);
        }

        return $next($request);
    }
}
