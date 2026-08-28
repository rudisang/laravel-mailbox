<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;

afterEach(function (): void {
    $this->artisan('config:clear')->assertSuccessful();
    $this->artisan('route:clear')->assertSuccessful();
});

it('supports cached configuration and controller-only mailbox routes', function () {
    $this->artisan('config:cache')->assertSuccessful();
    $this->artisan('route:cache')->assertSuccessful();

    $mailboxRoutes = collect(Route::getRoutes())->filter(
        static fn (RouteObject $route): bool => str_starts_with((string) $route->getName(), 'mailbox.'),
    );

    expect($mailboxRoutes)->not->toBeEmpty();

    $mailboxRoutes->each(function (RouteObject $route): void {
        expect($route->getActionName())->not->toBe('Closure');
    });
});
