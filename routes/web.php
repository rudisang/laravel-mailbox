<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rudisang\Mailbox\Http\Controllers\AssetController;
use Rudisang\Mailbox\Http\Controllers\InboxController;
use Rudisang\Mailbox\Http\Controllers\MessageController;
use Rudisang\Mailbox\Http\Controllers\PartController;
use Rudisang\Mailbox\Http\Controllers\PreviewController;
use Rudisang\Mailbox\Http\Controllers\StatusController;
use Rudisang\Mailbox\Http\Middleware\Authorize;
use Rudisang\Mailbox\Http\Middleware\SecurityHeaders;
use Rudisang\Mailbox\Http\Routing;

$middleware = array_values(array_unique(array_merge((array) config('mailbox.middleware', ['web']), [Authorize::class, SecurityHeaders::class])));

Route::group(['prefix' => trim((string) config('mailbox.path', '_mailbox'), '/'), 'as' => 'mailbox.', 'middleware' => $middleware], function (): void {
    Route::get('/', [InboxController::class, 'index'])->name('inbox');
    Route::post('/clear', [InboxController::class, 'clear'])->name('clear');
    Route::get('/api/status', [StatusController::class, 'show'])->name('status');
    Route::get('/assets/{file}', [AssetController::class, 'show'])->where('file', '[a-z]+\.(css|js)')->name('asset');

    Route::prefix('/messages/{id}')->where(['id' => Routing::ulid()])->group(function (): void {
        Route::get('/', [MessageController::class, 'show'])->name('message');
        Route::get('/preview/html', [PreviewController::class, 'html'])->name('preview.html');
        Route::get('/preview/text', [PreviewController::class, 'text'])->name('preview.text');
        Route::get('/raw', [MessageController::class, 'raw'])->name('raw');
        Route::get('/parts/{part}', [PartController::class, 'show'])->where('part', Routing::ulid())->name('part');
        Route::post('/read', [MessageController::class, 'read'])->name('read');
        Route::delete('/', [MessageController::class, 'destroy'])->name('destroy');
    });
});
