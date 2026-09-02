<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Security\Diagnostics;
use Rudisang\Mailbox\Testing\DiagnosticsWriter;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Rudisang\Mailbox\Transport\LocalTransportFactory;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;
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

    expect($m->bcc())->toHaveCount(1)
        ->and(file_get_contents($m->saveEml($this->dir.'/nested/eml/m.eml')))->toBe($m->raw());

    if (PHP_OS_FAMILY !== 'Windows') {
        // fileperms() does not reflect POSIX modes on Windows.
        expect(fileperms($this->dir.'/nested/eml') & 0777)->toBe(0700);
    }
    $json = json_decode(file_get_contents($m->saveDiagnostics($this->dir.'/d.json')), true);
    expect($json['rules_version'])->toBe(Diagnostics::RULES_VERSION)->and($json['capture_id'])->toBe($m->id());
    $xml = simplexml_load_file($m->saveDiagnostics($this->dir.'/d.xml', 'junit'));
    expect($xml)->not->toBeFalse()
        ->and((string) $xml['tests'])->toBe((string) $xml->testsuite['tests'])
        ->and((string) $xml['failures'])->toBe((string) $xml->testsuite['failures'])
        ->and((string) $xml->testsuite['name'])->toBe('mailbox:'.$m->id())
        ->and($xml->testsuite->testcase)->not->toBeEmpty()
        ->and((string) $xml->testsuite->testcase[0]['classname'])->toBe('mailbox');
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
            ['rule' => 'rule.error<&"', 'severity' => 'error', 'message' => 'Failed <unsafe> & "quoted".', 'evidence' => ['value' => "<unsafe>\u{FFFF}"]],
            ['rule' => 'rule.warning', 'severity' => 'warning', 'message' => 'Warned.', 'evidence' => 1],
            ['rule' => 'rule.info', 'severity' => 'info', 'message' => 'Informed.', 'evidence' => false],
        ],
    ]));

    expect($empty)->not->toBeFalse()
        ->and((string) $empty['tests'])->toBe('1')
        ->and((string) $empty['failures'])->toBe('0')
        ->and((string) $empty->testsuite->testcase['name'])->toBe('no-findings')
        ->and((string) $empty->testsuite->testcase['classname'])->toBe('mailbox')
        ->and($xml)->not->toBeFalse()
        ->and((string) $xml['tests'])->toBe('3')
        ->and((string) $xml['failures'])->toBe('1')
        ->and((string) $xml->testsuite['name'])->toBe('mailbox:capture<&"')
        ->and((string) $xml->testsuite['tests'])->toBe('3')
        ->and((string) $xml->testsuite['failures'])->toBe('1')
        ->and((string) $xml->testsuite->testcase[0]['name'])->toBe('rule.error<&"')
        ->and((string) $xml->testsuite->testcase[0]['classname'])->toBe('mailbox')
        ->and($xml->testsuite->testcase[0]->failure)->not->toBeEmpty()
        ->and((string) $xml->testsuite->testcase[0]->failure)->not->toContain("\u{FFFF}")
        ->and($xml->testsuite->testcase[1]->{'system-out'})->not->toBeEmpty()
        ->and($xml->testsuite->testcase[2]->{'system-out'})->not->toBeEmpty();
});

it('refuses to export a missing or empty raw message blob', function () {
    Mail::to('ada@example.com')->send(new WelcomeMail('Ada'));
    $message = mailbox()->latest();
    $rawPath = mailboxPaths()->raw($message->id());

    unlink($rawPath);

    expect(fn () => $message->saveEml($this->dir.'/missing.eml'))
        ->toThrow(RuntimeException::class, $message->id());

    file_put_contents($rawPath, '');

    expect(fn () => $message->saveEml($this->dir.'/empty.eml'))
        ->toThrow(RuntimeException::class, $message->id());
});

it('redacts a literal bcc header from unsupported raw-message fixtures', function () {
    $raw = new RawMessage("From: sender@example.com\r\nTo: buyer@example.com\r\nBcc: audit@example.com\r\nSubject: raw\r\n\r\nbody");
    $envelope = new Envelope(new Address('sender@example.com'), [new Address('buyer@example.com')]);

    app(LocalTransportFactory::class)->make(['transport' => 'local'])->send($raw, $envelope);
    $message = mailbox()->latest();
    $fixture = file_get_contents($message->saveFixture($this->dir.'/unsupported.json'));

    expect($message->parseStatus())->toBe('unsupported')
        ->and($message->rawHeaders())->toContain(['Bcc', 'audit@example.com'])
        ->and($fixture)->not->toBeFalse()
        ->and($fixture)->not->toContain('audit@example.com');
});

it('pins the symfony-generated content id shape used by mime snapshots', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $inline = (new DataPart($png, 'logo.png', 'image/png'))->asInline();
    $contentId = $inline->getContentId();
    $email = (new Email)
        ->from('sender@example.com')
        ->to('buyer@example.com')
        ->subject('Generated CID')
        ->html('<p>Logo: <img src="cid:'.$contentId.'"></p>')
        ->addPart($inline);

    app(LocalTransportFactory::class)->make(['transport' => 'local'])->send($email);
    $capturedInline = array_values(array_filter(
        mailbox()->latest()->parts(),
        static fn ($part): bool => $part->isInline,
    ))[0];

    expect($capturedInline->contentId)->toMatch('/^[0-9a-f]{32}@symfony$/');
});
