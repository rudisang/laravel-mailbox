<?php

declare(strict_types=1);

use Rudisang\Mailbox\Mime\ExtractedMessage;
use Rudisang\Mailbox\Mime\StructuredMessageExtractor;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Tests\Fixtures\Emails;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\TextPart;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/mailbox-extract-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    $this->extractor = new StructuredMessageExtractor(Limits::fromConfig([]));
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

it('extracts a plain message as a single text part', function () {
    $m = $this->extractor->extract(Emails::plain(), $this->dir);

    expect($m->parseStatus)->toBe('ok')
        ->and($m->subject)->toBe('Fixture subject')
        ->and($m->from)->toBe([['address' => 'sender@example.com', 'name' => 'Sender']])
        ->and($m->to[0]['address'])->toBe('to@example.com')
        ->and($m->parts)->toHaveCount(1)
        ->and($m->textPartId)->toBe($m->parts[0]->id)
        ->and($m->htmlPartId)->toBeNull()
        ->and(file_get_contents($m->parts[0]->blobPath))->toBe('Plain body text')
        ->and($m->previewText)->toBe('Plain body text')
        ->and($m->parts[0]->depth)->toBe(0);
});

it('converts declared text charset aliases for preview and search facts', function (string $bytes, string $charset, string $expected) {
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Legacy charset')
        ->setBody(new TextPart($bytes, $charset, 'plain', '8bit'));

    $message = $this->extractor->extract($email, $this->dir);

    expect($message->previewText)->toBe($expected)
        ->and($message->searchText)->toContain(mb_strtolower($expected, 'UTF-8'))
        ->and(file_get_contents($message->parts[0]->blobPath))->toBe($bytes);
})->with([
    'ISO-8859-1 canonical name' => ["caf\xe9", 'iso-8859-1', 'café'],
    'latin1 alias' => ["caf\xe9", 'latin1', 'café'],
    'Shift_JIS' => ["\x83e\x83X\x83g", 'Shift_JIS', 'テスト'],
]);

it('falls back to UTF-8 substitution for an unknown charset without warnings', function () {
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Unknown charset')
        ->setBody(new TextPart("\xfftext", 'x-nope', 'plain', '8bit'));

    $message = $this->extractor->extract($email, $this->dir);

    expect($message->previewText)->toBe('?text')
        ->and($message->searchText)->toContain('?text');
});

it('extracts multipart/alternative with ordered children', function () {
    $m = $this->extractor->extract(Emails::alternative(), $this->dir);
    $types = array_map(fn ($p) => [$p->depth, $p->mediaType.'/'.$p->mediaSubtype], $m->parts);

    expect($types)->toBe([[0, 'multipart/alternative'], [1, 'text/plain'], [1, 'text/html']])
        ->and($m->parts[1]->parentId)->toBe($m->parts[0]->id)
        ->and($m->parts[0]->blobPath)->toBeNull()
        ->and($m->htmlPartId)->toBe($m->parts[2]->id)
        ->and($m->textPartId)->toBe($m->parts[1]->id);
});

it('excludes non-visible html elements from preview and search text', function () {
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->html('<style>.x{color:red}</style><script>alert(1)</script><noscript>fallback</noscript><template>hidden</template><p>Visible words</p>');

    $message = $this->extractor->extract($email, $this->dir);

    expect($message->previewText)->toBe('Visible words')
        ->and($message->searchText)->toContain('visible words')
        ->not->toContain('alert')
        ->not->toContain('color:red')
        ->not->toContain('fallback')
        ->not->toContain('hidden');
});

it('extracts mixed attachments with filenames, dispositions and decoded blobs', function () {
    $m = $this->extractor->extract(Emails::mixedWithAttachments(), $this->dir);
    $attachments = array_values(array_filter($m->parts, fn ($p) => $p->isAttachment));

    expect($attachments)->toHaveCount(2)
        ->and($m->attachmentCount)->toBe(2)
        ->and($attachments[0]->filename)->toBe('invoice.pdf')
        ->and($attachments[0]->contentType)->toContain('application/pdf')
        ->and($attachments[0]->disposition)->toBe('attachment')
        ->and(file_get_contents($attachments[0]->blobPath))->toBe('%PDF-1.4 fake')
        ->and($attachments[0]->sha256)->toBe(hash('sha256', '%PDF-1.4 fake'))
        ->and($attachments[0]->decodedBytes)->toBe(13)
        ->and($m->parts[0]->mediaType)->toBe('multipart');
});

it('extracts related inline parts with content ids', function () {
    $m = $this->extractor->extract(Emails::relatedWithCid(), $this->dir);
    $inline = array_values(array_filter($m->parts, fn ($p) => $p->isInline));

    expect($inline)->toHaveCount(1)
        ->and($inline[0]->contentId)->toBe('logo')
        ->and($inline[0]->mediaSubtype)->toBe('png')
        ->and($inline[0]->isAttachment)->toBeFalse()
        ->and($m->attachmentCount)->toBe(0);
});

it('stores nested message/rfc822 bodies raw', function () {
    $message = $this->extractor->extract(Emails::nestedRfc822(), $this->dir);
    $nested = array_values(array_filter($message->parts, fn ($part) => $part->mediaType.'/'.$part->mediaSubtype === 'message/rfc822'))[0];
    $blob = file_get_contents($nested->blobPath);

    expect($blob)->toContain('Subject: Inner message')
        ->and($blob)->toContain('Plain body text')
        ->and($nested->transferEncoding)->toBeNull();
});

it('records calendar parts', function () {
    $calendar = $this->extractor->extract(Emails::calendar(), $this->dir);

    expect(array_map(fn ($p) => $p->mediaType.'/'.$p->mediaSubtype, $calendar->parts))->toContain('text/calendar');
});

it('keeps bcc as a separate semantic fact', function () {
    $m = $this->extractor->extract(Emails::bccOnly(), $this->dir);

    expect($m->bcc)->toBe([['address' => 'hidden@example.com', 'name' => 'Hidden']])
        ->and($m->to)->toBe([])
        ->and($m->searchText)->not->toContain('hidden@example.com');
});

it('collects tags and metadata headers', function () {
    $m = $this->extractor->extract(Emails::customHeaders(), $this->dir);

    expect($m->tags)->toBe(['billing'])->and($m->metadata)->toBe(['user_id' => '42']);
});

it('decodes unicode and long filenames', function () {
    $m = $this->extractor->extract(Emails::rfc2231Filename(), $this->dir);
    $attachment = array_values(array_filter($m->parts, fn ($p) => $p->isAttachment))[0];

    expect($attachment->filename)->toStartWith('résumé — 履歴書 🚀');
    expect($this->extractor->extract(Emails::unicodeHeaders(), $this->dir)->subject)->toBe('Ünïcödé — 日本語 🚀');
});

it('marks the message partial when the part limit is exceeded but keeps what fits', function () {
    $extractor = new StructuredMessageExtractor(Limits::fromConfig(['parts' => 5]));

    $m = $extractor->extract(Emails::manyParts(10), $this->dir);

    expect($m->parseStatus)->toBe('partial')
        ->and($m->parseError)->toBe('limit:parts')
        ->and(count($m->parts))->toBeLessThanOrEqual(5);
});

it('marks a bodiless email as failed with no_body', function () {
    $email = (new Email)->from('a@example.com')->to('b@example.com')->subject('x');

    $m = $this->extractor->extract($email, $this->dir);

    expect($m->parseStatus)->toBe('failed')
        ->and($m->parseError)->toBe('no_body')
        ->and($m->parts)->toBe([]);
});

it('marks the message partial when the depth limit is exceeded', function () {
    $extractor = new StructuredMessageExtractor(Limits::fromConfig(['depth' => 1]));
    $email = Emails::relatedWithCid()->attach('kept attachment', 'kept.txt', 'text/plain');

    $m = $extractor->extract($email, $this->dir);
    $kept = array_values(array_filter($m->parts, fn ($part) => $part->filename === 'kept.txt'))[0];

    expect($m->parseStatus)->toBe('partial')
        ->and($m->parseError)->toBe('limit:depth')
        ->and($m->parts)->not->toBeEmpty()
        ->and($kept->depth)->toBe(1)
        ->and(file_exists($kept->blobPath))->toBeTrue();
});

it('records a per-part failure and continues with siblings', function () {
    $email = Emails::plain();
    $email->addPart(new DataPart(new File('/nonexistent/path.bin'), 'x.bin'));
    $email->attach('normal attachment', 'normal.txt', 'text/plain');

    set_error_handler(static fn (): bool => true);

    try {
        $m = $this->extractor->extract($email, $this->dir);
    } finally {
        restore_error_handler();
    }

    $sibling = array_values(array_filter($m->parts, fn ($part) => $part->filename === 'normal.txt'))[0];

    expect($m->parseStatus)->toBe('partial')
        ->and($m->parseError)->toStartWith('part:')
        ->and(strlen((string) $m->parseError))->toBeLessThanOrEqual(120)
        ->and(file_exists($sibling->blobPath))->toBeTrue();
});

it('bounds preview and search text', function () {
    $extractor = new StructuredMessageExtractor(Limits::fromConfig(['search_text_bytes' => 1024]));
    $email = Emails::base()->text(str_repeat('word ', 5000));

    $m = $extractor->extract($email, $this->dir);

    expect(strlen((string) $m->searchText))->toBeLessThanOrEqual(1024)
        ->and(mb_strlen((string) $m->previewText))->toBeLessThanOrEqual(200);
});

it('creates an unsupported extraction result for non-email messages', function () {
    $m = ExtractedMessage::unsupported();

    expect($m->parseStatus)->toBe('unsupported')
        ->and($m->parseError)->toBeNull()
        ->and($m->subject)->toBeNull()
        ->and($m->parts)->toBe([])
        ->and($m->attachmentCount)->toBe(0)
        ->and($m->decodedBytes)->toBe(0);
});
