<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Security\Diagnostics;
use Rudisang\Mailbox\Testing\DiagnosticsWriter;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Workbench\App\Mail\InvoiceMail;
use Workbench\App\Mail\WelcomeMail;

uses(InteractsWithMailbox::class);

beforeEach(fn () => $this->dir = sys_get_temp_dir().'/mailbox-snap-'.bin2hex(random_bytes(4)));
afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

it('produces stable field-aware snapshots', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    $first = mailbox()->latest();
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    $second = mailbox()->latest();
    $inline = array_values(array_filter($first->parts(), fn ($part) => $part->isInline))[0];

    expect($inline->contentId)->not->toBeNull()
        ->and($first->htmlSnapshot())->toBe($second->htmlSnapshot())->toContain('cid:part-1')->not->toContain('cid:'.$inline->contentId)
        ->and($first->headersSnapshot())->toContain('Message-ID: <message-id>')->toContain('Date: <date>')->toContain('Content-Type: '.$first->header('Content-Type'))
        ->and($second->headersSnapshot())->toContain('Message-ID: <message-id>')->toContain('Date: <date>')->toContain('Content-Type: '.$second->header('Content-Type'))
        ->and($first->mimeTreeSnapshot())->toBe($second->mimeTreeSnapshot())->toContain('multipart/')->toContain('image/png inline')
        ->and($first->textSnapshot())->toBe($first->text());
    $first->assertMatchesSnapshot($second->htmlSnapshot());
});

it('writes eml, json and junit artifacts and a redacted fixture', function () {
    mkdir($this->dir, 0755, true);
    Mail::to('buyer@example.com')->send(new InvoiceMail(5));
    $m = mailbox()->latest();

    expect(file_get_contents($m->saveEml($this->dir.'/m.eml')))->toBe($m->raw());
    $json = json_decode(file_get_contents($m->saveDiagnostics($this->dir.'/d.json')), true);
    expect($json['rules_version'])->toBe(Diagnostics::RULES_VERSION)->and($json['capture_id'])->toBe($m->id());
    $xml = simplexml_load_file($m->saveDiagnostics($this->dir.'/d.xml', 'junit'));
    expect((string) $xml['name'])->toBe('mailbox:'.$m->id())
        ->and($xml->testcase)->not->toBeEmpty();
    $fixture = json_decode(file_get_contents($m->saveFixture($this->dir.'/f.json')), true);
    expect($fixture['subject'])->toBe('Your invoice #5')
        ->and($fixture)->not->toHaveKey('bcc')->not->toHaveKey('context')->not->toHaveKey('envelope_recipients')
        ->and(array_column($fixture['raw_headers'], 0))->not->toContain('Bcc')
        ->and(array_column($fixture['attachments'], 'filename'))->toBe(['invoice-5.pdf', 'line-items.csv'])
        ->and(json_encode($fixture))->not->toContain('audit@example.com');
});

it('writes escaped junit findings with severity-specific elements', function () {
    $empty = simplexml_load_string(DiagnosticsWriter::junit(['capture_id' => 'empty', 'results' => []]));
    $xml = simplexml_load_string(DiagnosticsWriter::junit([
        'capture_id' => 'capture<&"',
        'results' => [
            ['rule' => 'rule.error<&"', 'severity' => 'error', 'message' => 'Failed <unsafe> & "quoted".', 'evidence' => ['value' => '<unsafe>']],
            ['rule' => 'rule.warning', 'severity' => 'warning', 'message' => 'Warned.', 'evidence' => 1],
            ['rule' => 'rule.info', 'severity' => 'info', 'message' => 'Informed.', 'evidence' => false],
        ],
    ]));

    expect($empty)->not->toBeFalse()
        ->and((string) $empty->testcase['name'])->toBe('no-findings')
        ->and($xml)->not->toBeFalse()
        ->and((string) $xml['name'])->toBe('mailbox:capture<&"')
        ->and((string) $xml['tests'])->toBe('3')
        ->and((string) $xml['failures'])->toBe('1')
        ->and((string) $xml->testcase[0]['name'])->toBe('rule.error<&"')
        ->and($xml->testcase[0]->failure)->not->toBeEmpty()
        ->and($xml->testcase[1]->{'system-out'})->not->toBeEmpty()
        ->and($xml->testcase[2]->{'system-out'})->not->toBeEmpty();
});
