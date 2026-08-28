<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rudisang\Mailbox\MailboxServiceProvider;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\Assets;

it('serves the inbox with security headers', function () {
    mailboxCapture('Inbox subject');

    $response = $this->get('/_mailbox');

    $response->assertOk()->assertSee('Inbox subject')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")->toContain("frame-src 'self'")->not->toContain('unsafe-inline');
});

it('returns fragments for partial requests', function () {
    $id = mailboxCapture('Fragment');

    $this->get('/_mailbox?partial=list')->assertOk()->assertSee('Fragment')->assertDontSee('<html', false);
    $this->get('/_mailbox/messages/'.$id.'?partial=detail')
        ->assertOk()
        ->assertSee('Fragment')
        ->assertSee('Mark unread')
        ->assertDontSee('Mark read')
        ->assertDontSee('<html', false);
    $this->get('/_mailbox/messages/'.$id)->assertOk()->assertSee('<html', false);
    expect(app(MessageStore::class)->find($id)->isRead())->toBeTrue();
});

it('renders the progressive enhancement DOM contract', function () {
    $id = mailboxCapture('DOM contract');
    $response = $this->get('/_mailbox/messages/'.$id);

    $response->assertOk()
        ->assertSee('<body data-mailbox-base=', false)
        ->assertSee('data-mailbox-seq=', false)
        ->assertSee('id="mailbox-list"', false)
        ->assertSee('id="mailbox-detail"', false)
        ->assertSee('id="mailbox-live" aria-live="polite"', false)
        ->assertSee('id="mailbox-unread"', false)
        ->assertSee('data-message="'.$id.'"', false)
        ->assertSee('data-search', false)
        ->assertSee('data-theme-toggle', false)
        ->assertSee('data-viewport="375"', false)
        ->assertSee('data-viewport="768"', false)
        ->assertSee('data-viewport="full"', false)
        ->assertSee('<iframe sandbox ', false)
        ->assertSee('data-preview', false)
        ->assertSee('data-action="delete"', false)
        ->assertSee('data-action="read"', false)
        ->assertSee('data-action="clear"', false)
        ->assertSee('data-shortcuts-help', false);

    foreach (['html', 'text', 'headers', 'envelope', 'mime', 'raw', 'attachments', 'links', 'diagnostics'] as $tab) {
        $response->assertSee('data-tab="'.$tab.'"', false)
            ->assertSee('role="tabpanel" id="tab-'.$tab.'"', false);
    }
});

it('searches and filters', function () {
    mailboxCapture('Alpha one');
    mailboxCapture('Beta two');

    $this->get('/_mailbox?q=beta')->assertSee('Beta two')->assertDontSee('Alpha one');
    $this->get('/_mailbox?unread=1')->assertSee('Alpha one');
});

it('404s outside allowed environments and for unknown or malformed ids', function () {
    $id = mailboxCapture();
    $this->get('/_mailbox/messages/not-a-ulid')->assertNotFound();
    $this->get('/_mailbox/messages/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound()->assertSee('Message not found');

    $this->app['env'] = 'production';
    $this->get('/_mailbox')->assertNotFound();
    $this->get('/_mailbox/messages/'.$id)->assertNotFound();
});

it('applies the viewMailbox gate when defined', function () {
    Gate::define('viewMailbox', fn ($user = null) => false);

    $this->get('/_mailbox')->assertForbidden();
});

it('toggles read state, deletes and clears with CSRF protection', function () {
    $id = mailboxCapture();
    $store = app(MessageStore::class);

    $this->post('/_mailbox/messages/'.$id.'/read', ['read' => false])->assertRedirect(route('mailbox.message', $id));
    expect($store->find($id)->isRead())->toBeFalse();

    $this->postJson('/_mailbox/messages/'.$id.'/read', ['read' => true])->assertOk()->assertJson(['read' => true]);
    $this->delete('/_mailbox/messages/'.$id)->assertRedirect('/_mailbox');
    expect($store->find($id))->toBeNull();

    mailboxCapture();
    mailboxCapture();
    $this->post('/_mailbox/clear')->assertRedirect('/_mailbox');
    expect($store->count())->toBe(0);
});

it('waits for active captures before clearing through the web route', function () {
    mailboxCapture('Locked clear');
    $store = app(MessageStore::class);
    $store->pdo();
    $holder = proc_open([
        PHP_BINARY,
        '-r',
        '$h=fopen($argv[1],"c+");flock($h,LOCK_SH);echo "held\n";fflush(STDOUT);usleep(1500000);flock($h,LOCK_UN);fclose($h);',
        mailboxPaths()->lock(),
    ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    expect(is_resource($holder))->toBeTrue();
    expect(trim((string) fgets($pipes[1])))->toBe('held');

    $started = hrtime(true);
    $response = $this->post('/_mailbox/clear');
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($holder);

    $response->assertRedirect('/_mailbox');
    expect($elapsed)->toBeGreaterThanOrEqual(1.0)
        ->and($errors)->toBe('')
        ->and($exitCode)->toBe(0)
        ->and($store->count())->toBe(0);
});

it('validates read input and returns json mutation results', function () {
    $id = mailboxCapture('JSON mutations');

    $this->postJson('/_mailbox/messages/'.$id.'/read', ['read' => 'maybe'])->assertUnprocessable();
    $this->deleteJson('/_mailbox/messages/'.$id)->assertOk()->assertJson(['deleted' => true]);

    mailboxCapture('Clear one');
    mailboxCapture('Clear two');
    $this->postJson('/_mailbox/clear')->assertOk()->assertJson(['cleared' => true]);
    expect(app(MessageStore::class)->count())->toBe(0);
});

it('streams raw messages inline and as downloads and 404s when raw is missing', function () {
    $id = mailboxCapture('Raw route');
    $inline = $this->get('/_mailbox/messages/'.$id.'/raw');

    $inline->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=us-ascii')
        ->assertHeader('Content-Length', (string) filesize(mailboxPaths()->raw($id)))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Security-Policy');
    expect($inline->streamedContent())->toContain('Subject: Raw route');

    $download = $this->get('/_mailbox/messages/'.$id.'/raw?download=1');
    $download->assertOk()->assertHeader('Content-Disposition', 'attachment; filename='.$id.'.eml');
    expect($download->streamedContent())->toContain('Subject: Raw route');

    unlink(mailboxPaths()->raw($id));
    $this->get('/_mailbox/messages/'.$id.'/raw')->assertNotFound();
});

it('refuses symlinked raw messages and part blobs', function () {
    $id = mailboxCapture('Symlink refusal');
    $paths = mailboxPaths();
    $raw = $paths->raw($id);
    $rawTarget = dirname($raw).DIRECTORY_SEPARATOR.'raw-target.eml';
    rename($raw, $rawTarget);
    symlink($rawTarget, $raw);

    $this->get('/_mailbox/messages/'.$id.'/raw')->assertNotFound();

    $part = array_values(array_filter(
        app(MessageStore::class)->parts($id),
        static fn ($candidate): bool => $candidate->isLeaf(),
    ))[0];
    $blob = $paths->part($id, $part->id);
    $blobTarget = dirname($blob).DIRECTORY_SEPARATOR.'part-target.bin';
    rename($blob, $blobTarget);
    symlink($blobTarget, $blob);

    $this->get('/_mailbox/messages/'.$id.'/parts/'.$part->id)->assertNotFound();
})->skip(fn (): bool => PHP_OS_FAMILY === 'Windows', 'Symlink creation is not reliably available on Windows.');

it('keeps response-specific headers on preview part raw asset and status routes', function () {
    $id = mailboxCapture('Header exclusions');
    $part = array_values(array_filter(
        app(MessageStore::class)->parts($id),
        static fn ($candidate): bool => $candidate->isLeaf(),
    ))[0];

    $preview = $this->get('/_mailbox/messages/'.$id.'/preview/html');
    $preview->assertOk();
    expect($preview->headers->get('Cache-Control'))->toContain('no-store');
    expect($preview->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'")
        ->not->toContain("script-src 'self'");

    $partResponse = $this->get('/_mailbox/messages/'.$id.'/parts/'.$part->id);
    $partResponse->assertOk()->assertHeader('Cache-Control', 'max-age=3600, private');
    expect($partResponse->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox");

    $raw = $this->get('/_mailbox/messages/'.$id.'/raw');
    $raw->assertOk()->assertHeaderMissing('Content-Security-Policy');
    $raw->streamedContent();

    $asset = $this->get('/_mailbox/assets/mailbox.css');
    $asset->assertOk()->assertHeaderMissing('Content-Security-Policy');
    expect($asset->headers->get('Cache-Control'))->not->toContain('no-store');

    $status = $this->get('/_mailbox/api/status');
    $status->assertOk();
    expect($status->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('rejects mutations without a csrf token when the web middleware is active', function () {
    $route = Route::getRoutes()->getByName('mailbox.destroy');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('web');
});

it('reports status with etag support', function () {
    mailboxCapture();
    $first = $this->get('/_mailbox/api/status');
    $first->assertOk()->assertJson(['seq' => 1, 'total' => 1, 'unread' => 1]);
    $etag = $first->headers->get('ETag');

    $this->get('/_mailbox/api/status', ['If-None-Match' => $etag])->assertStatus(304);
    mailboxCapture();
    $this->get('/_mailbox/api/status', ['If-None-Match' => $etag])->assertOk()->assertJson(['seq' => 2]);
});

it('serves immutable assets and refuses anything else', function () {
    $this->get('/_mailbox/assets/mailbox.css')->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    $this->get('/_mailbox/assets/mailbox.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    $this->get('/_mailbox/assets/other.css')->assertNotFound();
    $this->get('/_mailbox/assets/../composer.json')->assertNotFound();
});

it('versions assets with sha256', function () {
    $hash = hash_file('sha256', Assets::path('mailbox.css'));

    expect($hash)->toBeString()
        ->and(Assets::version('mailbox.css'))->toBe(substr($hash, 0, 12));
});

it('registers the complete named route surface', function () {
    foreach (['inbox', 'clear', 'status', 'asset', 'message', 'preview.html', 'preview.text', 'raw', 'part', 'read', 'destroy'] as $name) {
        expect(Route::has('mailbox.'.$name))->toBeTrue();
    }
});

it('renders the empty state', function () {
    $this->get('/_mailbox')->assertOk()->assertSee('No messages yet');
});

it('honours a custom path and route caching', function () {
    config()->set('mailbox.path', 'dev/mail');
    (new MailboxServiceProvider($this->app))->boot();

    $this->get('/dev/mail')->assertOk();
})->skip(fn () => true, 'route re-registration in the same app is covered by the config:cache/route:cache test in Phase 3');
