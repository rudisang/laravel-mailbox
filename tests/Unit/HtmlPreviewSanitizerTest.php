<?php

declare(strict_types=1);

use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
use Rudisang\Mailbox\Support\Limits;

beforeEach(function () {
    $this->sanitizer = new HtmlPreviewSanitizer(Limits::fromConfig([]));
    $this->hostile = file_get_contents(__DIR__.'/../Fixtures/xss/hostile.html');
    $this->result = $this->sanitizer->sanitize($this->hostile, ['logo' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], 'http://app.test/_mailbox/messages/01ARZ3NDEKTSV4RRFFQ69G5FAW/parts');
});

it('removes every active element and handler', function () {
    $doc = $this->result->document;

    foreach (['<script', '<iframe', '<object', '<embed', '<form', '<base', 'http-equiv', 'onload', 'onerror', '<svg', '<math', '/logout', '/_mailbox/clear', 'srcset', 'poster'] as $needle) {
        expect(strtolower($doc))->not->toContain(strtolower($needle));
    }
    expect(strtolower($doc))->not->toContain('src="https://evil.example.com')->not->toContain('href="https://evil.example.com');
    expect($this->result->removed['script'])->toBeGreaterThanOrEqual(2)
        ->and($this->result->removed['form'])->toBe(1)
        ->and($this->result->removed['iframe'])->toBe(1)
        ->and($this->result->removed['meta_refresh'])->toBe(1)
        ->and($this->result->removed['base'])->toBe(1)
        ->and($this->result->removed['event_handlers'])->toBeGreaterThanOrEqual(3)
        ->and($this->result->removed['javascript_urls'])->toBeGreaterThanOrEqual(2);
});

it('keeps safe formatting, inline styles and style blocks verbatim', function () {
    $doc = $this->result->document;

    expect($doc)->toContain('<b>Safe formatting</b>')
        ->toContain('class="ok"')
        ->toContain('a > b { color: red }')
        ->toContain('style="')
        ->and($this->result->removed['style_blocks_kept'])->toBeGreaterThanOrEqual(1);
});

it('escapes uppercase style terminators without emitting scripts', function () {
    $result = $this->sanitizer->sanitize(
        '<style>.x::after { content: "</STYLE><script>alert(1)</script>"; }</style><p>Visible</p>',
        [],
        'http://x/parts',
    );

    expect(strtolower($result->document))->not->toContain('<script')
        ->and($result->document)->toContain('<p>Visible</p>');
});

it('re-injects only validated cid part urls and data images', function () {
    $doc = $this->result->document;

    expect($doc)->toContain('src="http://app.test/_mailbox/messages/01ARZ3NDEKTSV4RRFFQ69G5FAW/parts/01ARZ3NDEKTSV4RRFFQ69G5FAV"')
        ->toContain('src="data:image/png;base64,iVBORw0KGgo="')
        ->and(substr_count($doc, 'src="'))->toBe(2);
});

it('neutralises links but reports them, with openable only for http(s)/mailto', function () {
    expect($this->result->document)->not->toContain('href=');
    $urls = array_column($this->result->links, 'url');
    expect($urls)->toContain('https://acme.test/reset-password?token=SECRET123')->toContain('mailto:x@example.com');
    $byUrl = array_column($this->result->links, 'openable', 'url');
    expect($byUrl['https://acme.test/reset-password?token=SECRET123'])->toBeTrue()
        ->and($byUrl['mailto:x@example.com'])->toBeTrue();
    foreach ($this->result->links as $link) {
        if (str_starts_with(strtolower($link['url']), 'javascript')) {
            expect($link['openable'])->toBeFalse();
        }
    }
});

it('records and classifies links from the same normalised url', function () {
    $result = $this->sanitizer->sanitize(
        '<a href="h&#10;ttps://example.test/path">normalised</a>',
        [],
        'http://x/parts',
    );

    expect($result->links)->toHaveCount(1)
        ->and($result->links[0]['url'])->toBe('https://example.test/path')
        ->and($result->links[0]['openable'])->toBeTrue();
});

it('reports remote images and tracking pixels', function () {
    expect($this->result->remoteImages)->toContain('https://evil.example.com/pixel.gif')
        ->and($this->result->trackingPixels)->toBeGreaterThanOrEqual(1);
});

it('produces a self-contained document with no scripts even for empty input', function () {
    $r = $this->sanitizer->sanitize('', [], 'http://x/parts');

    expect($r->document)->toStartWith('<!doctype html>')->not->toContain('<script');
});

it('truncates oversized html', function () {
    $sanitizer = new HtmlPreviewSanitizer(Limits::fromConfig(['preview_bytes' => 16 * 1024]));

    $r = $sanitizer->sanitize('<p>'.str_repeat('a', 100 * 1024).'</p>', [], 'http://x/parts');

    expect($r->truncated)->toBeTrue()->and(strlen($r->document))->toBeLessThan(40 * 1024);
});

it('drops unknown cid references and non-image data uris', function () {
    $part = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $r = $this->sanitizer->sanitize(
        '<img src="cid:missing"><img src="data:text/html;base64,PHNjcmlwdD4=">'
        .'<img data-mailbox-part="'.$part.'"><img data-mailbox-data="data:image/png;base64,iVBORw0KGgo=">',
        ['known' => $part],
        'http://x/parts',
    );

    expect($r->document)->not->toContain('src=')->not->toContain('data-mailbox');
});

it('enforces the data image base64 length bound', function () {
    $kept = 'data:image/png;base64,'.str_repeat('A', 700000);
    $dropped = 'data:image/png;base64,'.str_repeat('A', 700001);
    $result = $this->sanitizer->sanitize(
        '<img alt="kept" src="'.$kept.'"><img alt="dropped" src="'.$dropped.'">',
        [],
        'http://x/parts',
    );

    expect($result->document)->toContain('src="'.$kept.'"')
        ->and(substr_count($result->document, 'src="data:image/png;base64,'))->toBe(1);
});
