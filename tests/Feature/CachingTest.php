<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;

// Every framework cache path is redirected into this test's private directory.
// Two reasons, both learned the hard way:
// - config:cache/route:cache write cache files; in the shared Testbench
//   skeleton's bootstrap/cache a parallel sibling (or a spawned queue worker)
//   booting inside that window would silently adopt this test's cached config.
// - route:cache boots a FRESH app in-process; if its package manifest path
//   pointed at the shared skeleton, it would rebuild packages.php with the
//   stock manifest — which omits the root package's provider — poisoning every
//   later boot of the skeleton (workers included). The env overrides below are
//   honoured by the fresh apps too, so nothing ever touches the shared caches.
const CACHE_PATH_KEYS = ['CONFIG', 'ROUTES', 'PACKAGES', 'SERVICES', 'EVENTS'];

// The env values are RELATIVE on purpose: the framework prepends basePath() to
// any cache path that does not start with / or \, and this app and the fresh
// apps share the skeleton base path, so a relative value resolves to the same
// private per-test directory in both — on every platform. (An absolute Windows
// path like C:\... fails that prefix check and would be mangled.)
beforeEach(function (): void {
    $relative = 'bootstrap/cache-'.basename($this->mailboxStoragePath);
    mkdir($this->app->basePath($relative), 0755, true);

    foreach (CACHE_PATH_KEYS as $key) {
        $_ENV['APP_'.$key.'_CACHE'] = $_SERVER['APP_'.$key.'_CACHE'] = $relative.'/'.strtolower($key).'.php';
    }
});

afterEach(function (): void {
    $this->artisan('config:clear')->assertSuccessful();
    $this->artisan('route:clear')->assertSuccessful();

    foreach (CACHE_PATH_KEYS as $key) {
        unset($_ENV['APP_'.$key.'_CACHE'], $_SERVER['APP_'.$key.'_CACHE']);
    }

    (new Filesystem)->deleteDirectory($this->app->basePath('bootstrap/cache-'.basename($this->mailboxStoragePath)));
});

it('supports cached configuration and controller-only mailbox routes', function () {
    // Assert on the router BEFORE running the cache commands: config:cache and
    // route:cache boot a fresh application in-process, and that boot re-points
    // every facade at the fresh app, so the router read afterwards would not be
    // this test's app. Controller-only routes are what make route:cache safe.
    $mailboxRoutes = collect(Route::getRoutes())->filter(
        static fn (RouteObject $route): bool => str_starts_with((string) $route->getName(), 'mailbox.'),
    );

    expect($mailboxRoutes)->not->toBeEmpty();

    $mailboxRoutes->each(function (RouteObject $route): void {
        expect($route->getActionName())->not->toBe('Closure');
    });

    $this->artisan('config:cache')->assertSuccessful();
    $this->artisan('route:cache')->assertSuccessful();
});
