<?php

declare(strict_types=1);

use Rudisang\Mailbox\Security\Diagnostics;
use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Support\Limits;

it('evaluates versioned rules', function () {
    $record = MessageRecord::fromRow(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'captured_at' => '2026-08-28T00:00:00Z', 'raw_sha256' => str_repeat('a', 64), 'raw_bytes' => 10, 'parse_status' => 'partial', 'parse_error' => 'limit:parts', 'subject' => null, 'from_json' => '[]', 'to_json' => '[]', 'cc_json' => '[]', 'bcc_json' => '[]', 'reply_to_json' => '[]', 'envelope_recipients_json' => '[]', 'tags_json' => '[]', 'metadata_json' => '{}', 'raw_headers_json' => json_encode([['Bcc', 'x@example.com']]), 'has_html' => 1, 'has_text' => 0, 'part_count' => 1, 'attachment_count' => 0, 'decoded_bytes' => 0, 'context_json' => '{}']);
    $preview = (new HtmlPreviewSanitizer(Limits::fromConfig([])))->sanitize(file_get_contents(__DIR__.'/../Fixtures/xss/hostile.html'), [], 'http://x/parts');

    $report = Diagnostics::evaluate($record, [], $preview, 150 * 1024);
    $rules = array_column($report['results'], 'severity', 'rule');

    expect($report['rules_version'])->toBe(Diagnostics::RULES_VERSION)
        ->and($report['capture_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($rules['parse.status'])->toBe('warning')
        ->and($rules['limits.hit'])->toBe('warning')
        ->and($rules['raw.bcc_present'])->toBe('error')
        ->and($rules['html.scripts_removed'])->toBe('warning')
        ->and($rules['html.forms_removed'])->toBe('warning')
        ->and($rules['html.tracking_pixels'])->toBe('warning')
        ->and($rules['html.remote_images'])->toBe('info')
        ->and($rules['html.no_text_alternative'])->toBe('warning')
        ->and($rules['html.gmail_clipping'])->toBe('info')
        ->and($rules['message.no_subject'])->toBe('warning')
        ->and($rules['message.no_recipients'])->toBe('warning')
        ->and($rules['links.neutralised'])->toBe('info');
});

it('returns an empty result list for a clean message', function () {
    $record = MessageRecord::fromRow(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'captured_at' => '2026-08-28T00:00:00Z', 'raw_sha256' => str_repeat('a', 64), 'raw_bytes' => 10, 'parse_status' => 'ok', 'subject' => 'Hi', 'from_json' => '[]', 'to_json' => json_encode([['address' => 'a@b.c', 'name' => '']]), 'cc_json' => '[]', 'bcc_json' => '[]', 'reply_to_json' => '[]', 'envelope_recipients_json' => '[]', 'tags_json' => '[]', 'metadata_json' => '{}', 'raw_headers_json' => '[]', 'has_html' => 1, 'has_text' => 1, 'part_count' => 1, 'attachment_count' => 0, 'decoded_bytes' => 0, 'context_json' => '{}']);
    $preview = (new HtmlPreviewSanitizer(Limits::fromConfig([])))->sanitize('<p>clean</p>', [], 'http://x/parts');

    expect(Diagnostics::evaluate($record, [], $preview, 100)['results'])->toBe([]);
});
