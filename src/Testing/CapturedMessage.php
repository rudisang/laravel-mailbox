<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Testing;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Rudisang\Mailbox\Capture\RawHeaderBlock;
use Rudisang\Mailbox\Http\MessagePresenter;
use Rudisang\Mailbox\Security\AttachmentPolicy;
use Rudisang\Mailbox\Security\Diagnostics;
use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
use Rudisang\Mailbox\Security\PreviewResult;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\PartRecord;
use Rudisang\Mailbox\Support\StoragePaths;
use RuntimeException;

final class CapturedMessage
{
    private readonly MessagePresenter $presenter;

    private ?PreviewResult $preview = null;

    private bool $previewResolved = false;

    /** @var array<string, mixed>|null */
    private ?array $diagnostics = null;

    public function __construct(
        private readonly MessageRecord $record,
        private readonly MessageStore $store,
        private readonly StoragePaths $paths,
        private readonly HtmlPreviewSanitizer $sanitizer,
        AttachmentPolicy $policy,
    ) {
        $this->presenter = new MessagePresenter($store, $paths, $sanitizer, $policy);
    }

    public function id(): string
    {
        return $this->record->id;
    }

    public function seq(): int
    {
        return (int) $this->record->seq;
    }

    public function messageId(): ?string
    {
        return $this->record->messageId;
    }

    public function subject(): ?string
    {
        return $this->record->subject;
    }

    /** @return list<array{address: string, name: string}> */
    public function from(): array
    {
        return $this->record->from;
    }

    /** @return list<array{address: string, name: string}> */
    public function to(): array
    {
        return $this->record->to;
    }

    /** @return list<array{address: string, name: string}> */
    public function cc(): array
    {
        return $this->record->cc;
    }

    /** @return list<array{address: string, name: string}> */
    public function bcc(): array
    {
        return $this->record->bcc;
    }

    /** @return list<array{address: string, name: string}> */
    public function replyTo(): array
    {
        return $this->record->replyTo;
    }

    public function envelopeSender(): ?string
    {
        return $this->record->envelopeSender;
    }

    /** @return list<string> */
    public function envelopeRecipients(): array
    {
        return $this->record->envelopeRecipients;
    }

    /** @return list<array{0: string, 1: string}> */
    public function rawHeaders(): array
    {
        return $this->record->rawHeaders;
    }

    public function header(string $name): ?string
    {
        return RawHeaderBlock::first($this->rawHeaders(), $name);
    }

    /** @return list<string> */
    public function headers(string $name): array
    {
        return RawHeaderBlock::all($this->rawHeaders(), $name);
    }

    public function html(): ?string
    {
        return $this->presenter->html($this->record);
    }

    public function text(): ?string
    {
        return $this->presenter->text($this->record);
    }

    public function raw(): string
    {
        $contents = file_get_contents($this->paths->raw($this->id()));

        return $contents === false ? '' : $contents;
    }

    public function htmlSnapshot(): string
    {
        return Snapshots::html($this);
    }

    public function textSnapshot(): string
    {
        return Snapshots::text($this);
    }

    public function headersSnapshot(): string
    {
        return Snapshots::headers($this);
    }

    public function mimeTreeSnapshot(): string
    {
        return Snapshots::mimeTree($this);
    }

    public function assertMatchesSnapshot(string $expectedHtml): static
    {
        Assert::assertSame(
            $expectedHtml,
            $this->htmlSnapshot(),
            'Expected the HTML body to match the field-aware snapshot.',
        );

        return $this;
    }

    public function saveEml(string $path): string
    {
        return self::write($path, $this->raw());
    }

    public function saveDiagnostics(string $path, string $format = 'json'): string
    {
        $contents = match ($format) {
            'json' => DiagnosticsWriter::json($this->diagnostics()),
            'junit' => DiagnosticsWriter::junit($this->diagnostics()),
            default => throw new InvalidArgumentException(sprintf('Unsupported diagnostics format [%s].', $format)),
        };

        return self::write($path, $contents);
    }

    public function saveFixture(string $path): string
    {
        return self::write($path, DiagnosticsWriter::json(Snapshots::fixture($this)));
    }

    /** @return list<PartRecord> */
    public function parts(): array
    {
        return $this->store->parts($this->id());
    }

    /** @return list<PartRecord> */
    public function attachments(): array
    {
        return array_values(array_filter(
            $this->parts(),
            static fn (PartRecord $part): bool => $part->isAttachment,
        ));
    }

    public function attachmentContent(string $filename): ?string
    {
        foreach ($this->attachments() as $attachment) {
            if ($attachment->filename !== $filename) {
                continue;
            }

            $contents = file_get_contents($this->paths->part($this->id(), $attachment->id));

            return $contents === false ? null : $contents;
        }

        return null;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->record->tags;
    }

    /** @return array<string, string> */
    public function metadata(): array
    {
        return $this->record->metadata;
    }

    /** @return array<string, string|null> */
    public function context(): array
    {
        return $this->record->context;
    }

    /** @return 'ok'|'partial'|'failed'|'unsupported' */
    public function parseStatus(): string
    {
        return $this->record->parseStatus;
    }

    public function parseError(): ?string
    {
        return $this->record->parseError;
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        if ($this->diagnostics !== null) {
            return $this->diagnostics;
        }

        $htmlBytes = null;

        foreach ($this->parts() as $part) {
            if ($part->mediaType === 'text' && $part->mediaSubtype === 'html' && ! $part->isAttachment && $part->isLeaf()) {
                $htmlBytes = $part->decodedBytes;

                break;
            }
        }

        $preview = $this->preview();
        $diagnostics = Diagnostics::evaluate(
            $this->record,
            $this->parts(),
            $preview,
            $htmlBytes,
        );

        if ($preview !== null) {
            foreach ([
                'event_handlers' => ['html.event_handlers', 'Event handler attributes were removed.'],
                'javascript_urls' => ['html.javascript_urls', 'JavaScript URLs were removed.'],
            ] as $key => [$rule, $message]) {
                $count = $preview->removed[$key] ?? 0;

                if ($count > 0) {
                    $diagnostics['results'][] = [
                        'rule' => $rule,
                        'severity' => 'warning',
                        'message' => $message,
                        'evidence' => $count,
                    ];
                }
            }
        }

        return $this->diagnostics = $diagnostics;
    }

    /** @return list<array{text: string, url: string, openable: bool}> */
    public function links(): array
    {
        $preview = $this->preview();

        return $preview === null ? [] : $preview->links;
    }

    public function record(): MessageRecord
    {
        return $this->record;
    }

    public function assertFrom(string $address): static
    {
        return $this->assertOriginalAddress($this->from(), $address, 'From addresses');
    }

    public function assertTo(string $address): static
    {
        return $this->assertOriginalAddress($this->to(), $address, 'To recipients');
    }

    public function assertCc(string $address): static
    {
        return $this->assertOriginalAddress($this->cc(), $address, 'Cc recipients');
    }

    public function assertBcc(string $address): static
    {
        return $this->assertOriginalAddress($this->bcc(), $address, 'Bcc recipients');
    }

    public function assertReplyTo(string $address): static
    {
        return $this->assertOriginalAddress($this->replyTo(), $address, 'Reply-To addresses');
    }

    public function assertSubject(string $exact): static
    {
        Assert::assertTrue(
            $this->subject() === $exact,
            sprintf(
                'Expected subject [%s] on the original message; got [%s].',
                $exact,
                $this->subject() ?? 'null',
            ),
        );

        return $this;
    }

    public function assertSubjectContains(string $needle): static
    {
        $subject = $this->subject();
        Assert::assertTrue(
            $subject !== null && str_contains($subject, $needle),
            sprintf(
                'Expected subject of the original message to contain [%s]; got [%s].',
                $needle,
                $subject ?? 'null',
            ),
        );

        return $this;
    }

    public function assertHtmlContains(string $needle): static
    {
        $html = $this->html();
        Assert::assertTrue(
            $html !== null && str_contains($html, $needle),
            sprintf('Expected the HTML body of the original message to contain [%s].', $needle),
        );

        return $this;
    }

    public function assertHtmlNotContains(string $needle): static
    {
        $html = $this->html();
        Assert::assertTrue(
            $html === null || ! str_contains($html, $needle),
            sprintf('Expected the HTML body of the original message not to contain [%s].', $needle),
        );

        return $this;
    }

    public function assertTextContains(string $needle): static
    {
        $text = $this->text();
        Assert::assertTrue(
            $text !== null && str_contains($text, $needle),
            sprintf('Expected the text body of the original message to contain [%s].', $needle),
        );

        return $this;
    }

    public function assertSeeInHtml(string $text): static
    {
        $html = $this->html();
        $visible = $html === null
            ? ''
            : html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        Assert::assertTrue(
            str_contains($visible, $text),
            sprintf('Expected visible HTML text of the original message to contain [%s].', $text),
        );

        return $this;
    }

    public function assertHasAttachment(string $filename, ?string $mime = null): static
    {
        $found = false;

        foreach ($this->attachments() as $attachment) {
            if ($attachment->filename === $filename
                && ($mime === null || strcasecmp($attachment->mediaType.'/'.$attachment->mediaSubtype, $mime) === 0)) {
                $found = true;

                break;
            }
        }

        $description = $mime === null ? $filename : $filename.' with MIME type '.$mime;
        Assert::assertTrue(
            $found,
            sprintf('Expected attachment [%s] among the attachments of the original message.', $description),
        );

        return $this;
    }

    public function assertAttachmentCount(int $count): static
    {
        $actual = count($this->attachments());
        Assert::assertTrue(
            $actual === $count,
            sprintf(
                'Expected %d attachment(s) on the original message; got %d.',
                $count,
                $actual,
            ),
        );

        return $this;
    }

    public function assertHasInlineImage(string $contentId): static
    {
        $contentIds = [];

        foreach ($this->parts() as $part) {
            if ($part->isInline && $part->contentId !== null) {
                $contentIds[] = $part->contentId;
            }
        }

        Assert::assertTrue(
            in_array($contentId, $contentIds, true),
            sprintf(
                'Expected content ID [%s] among the inline images of the original message; got [%s].',
                $contentId,
                implode(', ', $contentIds),
            ),
        );

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): static
    {
        $actual = $this->header($name);
        Assert::assertTrue(
            $actual !== null,
            sprintf('Expected raw header [%s] to be present.', $name),
        );

        if ($value !== null) {
            Assert::assertTrue(
                $actual === $value,
                sprintf(
                    'Expected raw header [%s] to equal [%s]; got [%s].',
                    $name,
                    $value,
                    $actual,
                ),
            );
        }

        return $this;
    }

    public function assertRawHeaderMissing(string $name): static
    {
        Assert::assertTrue(
            ! RawHeaderBlock::has($this->rawHeaders(), $name),
            sprintf('Expected raw header [%s] to be missing.', $name),
        );

        return $this;
    }

    public function assertRawContains(string $needle): static
    {
        Assert::assertTrue(
            str_contains($this->raw(), $needle),
            sprintf('Expected the final raw message to contain [%s].', $needle),
        );

        return $this;
    }

    public function assertEnvelopeContains(string $address): static
    {
        Assert::assertTrue(
            self::stringListContains($this->envelopeRecipients(), $address),
            sprintf(
                'Expected [%s] among the envelope recipients; got [%s].',
                $address,
                implode(', ', $this->envelopeRecipients()),
            ),
        );

        return $this;
    }

    public function assertEnvelopeSender(string $address): static
    {
        $actual = $this->envelopeSender();
        Assert::assertTrue(
            $actual !== null && strcasecmp($actual, $address) === 0,
            sprintf('Expected envelope sender [%s]; got [%s].', $address, $actual ?? 'null'),
        );

        return $this;
    }

    public function assertTag(string $tag): static
    {
        Assert::assertTrue(
            in_array($tag, $this->tags(), true),
            sprintf(
                'Expected tag [%s] among the tags of the original message; got [%s].',
                $tag,
                implode(', ', $this->tags()),
            ),
        );

        return $this;
    }

    public function assertMetadata(string $key, string $value): static
    {
        $actual = $this->metadata()[$key] ?? null;
        Assert::assertTrue(
            $actual === $value,
            sprintf(
                'Expected metadata [%s] on the original message to equal [%s]; got [%s].',
                $key,
                $value,
                $actual ?? 'null',
            ),
        );

        return $this;
    }

    public function assertNoParseErrors(): static
    {
        Assert::assertTrue(
            $this->parseStatus() === 'ok' && $this->parseError() === null,
            sprintf(
                'Expected no errors while parsing the original message; status [%s], error [%s].',
                $this->parseStatus(),
                $this->parseError() ?? 'null',
            ),
        );

        return $this;
    }

    public function assertNoRemoteImages(): static
    {
        Assert::assertTrue(
            ! in_array('html.remote_images', $this->diagnosticRules(), true),
            'Expected no remote images in diagnostics for the HTML body of the original message.',
        );

        return $this;
    }

    public function assertNoScripts(): static
    {
        $scriptRules = ['html.scripts_removed', 'html.event_handlers', 'html.javascript_urls'];
        Assert::assertTrue(
            array_intersect($scriptRules, $this->diagnosticRules()) === [],
            'Expected no scripts in diagnostics for the HTML body of the original message.',
        );

        return $this;
    }

    /**
     * @param  list<array{address: string, name: string}>  $addresses
     */
    private function assertOriginalAddress(array $addresses, string $address, string $label): static
    {
        $actual = array_map(
            static fn (array $item): string => $item['address'],
            $addresses,
        );
        Assert::assertTrue(
            self::stringListContains($actual, $address),
            sprintf(
                'Expected [%s] among the %s of the original message; got [%s].',
                $address,
                $label,
                implode(', ', $actual),
            ),
        );

        return $this;
    }

    /** @param list<string> $values */
    private static function stringListContains(array $values, string $expected): bool
    {
        foreach ($values as $value) {
            if (strcasecmp($value, $expected) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function diagnosticRules(): array
    {
        $rules = [];
        $results = $this->diagnostics()['results'] ?? [];

        if (! is_array($results)) {
            return [];
        }

        foreach ($results as $result) {
            if (is_array($result) && isset($result['rule']) && is_string($result['rule'])) {
                $rules[] = $result['rule'];
            }
        }

        return $rules;
    }

    private function preview(): ?PreviewResult
    {
        if ($this->previewResolved) {
            return $this->preview;
        }

        $this->previewResolved = true;
        $html = $this->html();

        if ($html === null) {
            return null;
        }

        $cidMap = [];

        foreach ($this->parts() as $part) {
            if ($part->contentId !== null && $part->contentId !== '') {
                $cidMap[$part->contentId] = $part->id;
            }
        }

        return $this->preview = $this->sanitizer->sanitize(
            $html,
            $cidMap,
            '/_mailbox-testing/messages/'.$this->id().'/parts',
        );
    }

    private static function write(string $path, string $contents): string
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write mailbox artifact [%s].', $path));
        }

        return $path;
    }
}
