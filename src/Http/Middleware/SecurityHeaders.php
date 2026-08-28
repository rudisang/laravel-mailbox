<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();
        $routeName = $route instanceof Route ? $route->getName() : null;

        if ($this->isExcludedRoute($routeName)) {
            return $response;
        }

        $contentType = $response->headers->get('Content-Type') ?? '';

        if (! str_starts_with(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function isExcludedRoute(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        return str_starts_with($routeName, 'mailbox.preview.')
            || in_array($routeName, ['mailbox.part', 'mailbox.raw', 'mailbox.asset'], true);
    }

    private function contentSecurityPolicy(): string
    {
        return "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'self'";
    }
}
