<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Testing;

use PHPUnit\Framework\Assert;
use Rudisang\Mailbox\Security\AttachmentPolicy;
use Rudisang\Mailbox\Security\HtmlPreviewSanitizer;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Support\StoragePaths;

/** Public query and assertion API created by InteractsWithMailbox. */
final class MailboxTester
{
    private const PAGE_SIZE = 200;

    /** @internal The InteractsWithMailbox trait constructs testers for consumers. */
    public function __construct(
        private readonly MessageStore $store,
        private readonly StoragePaths $paths,
        private readonly HtmlPreviewSanitizer $sanitizer,
        private readonly AttachmentPolicy $policy,
        private readonly ?string $namespace,
        private int $highWater,
    ) {}

    public function namespace(): ?string
    {
        return $this->namespace;
    }

    public function anyNamespace(): self
    {
        return new self(
            $this->store,
            $this->paths,
            $this->sanitizer,
            $this->policy,
            null,
            $this->highWater,
        );
    }

    /** @return list<CapturedMessage> Captured messages, newest first. */
    public function all(): array
    {
        $records = [];
        $offset = 0;

        do {
            $filters = [
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ];

            if ($this->namespace !== null) {
                $filters['namespace'] = $this->namespace;
            }

            $page = $this->store->list($filters);
            array_push($records, ...$page);
            $offset += count($page);
        } while (count($page) === self::PAGE_SIZE);

        return array_map(fn (MessageRecord $record): CapturedMessage => $this->capture($record), $records);
    }

    public function latest(): CapturedMessage
    {
        $latest = $this->all()[0] ?? null;

        if ($latest === null) {
            Assert::fail('No mailbox captures in namespace '.$this->namespaceLabel());
        }

        return $latest;
    }

    public function count(): int
    {
        return $this->store->count($this->namespace === null ? [] : ['namespace' => $this->namespace]);
    }

    public function find(string $id): ?CapturedMessage
    {
        $record = $this->store->find($id);

        if ($record === null || ($this->namespace !== null && $record->namespace !== $this->namespace)) {
            return null;
        }

        return $this->capture($record);
    }

    /** @return list<CapturedMessage> */
    public function whereMessageId(string $id): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (CapturedMessage $message): bool => $message->messageId() === $id,
        ));
    }

    /** @return list<CapturedMessage> */
    public function whereTo(string $address): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (CapturedMessage $message): bool => self::hasAddress($message->to(), $address),
        ));
    }

    /** @return list<CapturedMessage> */
    public function whereSubject(string $subject): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (CapturedMessage $message): bool => $message->subject() !== null
                && strcasecmp($message->subject(), $subject) === 0,
        ));
    }

    /** @return list<CapturedMessage> */
    public function whereTag(string $tag): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (CapturedMessage $message): bool => in_array($tag, $message->tags(), true),
        ));
    }

    /** @return list<CapturedMessage> Newly captured messages, newest first. */
    public function waitForCapture(int $count = 1, float $timeout = 5.0): array
    {
        $deadline = microtime(true) + max(0.0, $timeout);

        while (true) {
            if ($this->store->countSince($this->highWater, $this->namespace) >= $count) {
                $messages = array_values(array_filter(
                    $this->all(),
                    fn (CapturedMessage $message): bool => $message->seq() > $this->highWater,
                ));

                foreach ($messages as $message) {
                    $this->highWater = max($this->highWater, $message->seq());
                }

                return $messages;
            }

            if (microtime(true) >= $deadline) {
                Assert::fail(sprintf(
                    'Timed out after %ss waiting for %d capture(s)',
                    self::formatSeconds($timeout),
                    $count,
                ));
            }

            usleep(50_000);
        }
    }

    public function assertCaptured(int $count): self
    {
        $actual = $this->count();
        Assert::assertSame(
            $count,
            $actual,
            sprintf(
                'Expected %d mailbox capture(s) in namespace %s; got %d.',
                $count,
                $this->namespaceLabel(),
                $actual,
            ),
        );

        return $this;
    }

    public function assertNothingCaptured(): self
    {
        return $this->assertCaptured(0);
    }

    public function clear(): void
    {
        foreach ($this->all() as $message) {
            $this->store->delete($message->id());
        }
    }

    private function capture(MessageRecord $record): CapturedMessage
    {
        return new CapturedMessage(
            $record,
            $this->store,
            $this->paths,
            $this->sanitizer,
            $this->policy,
        );
    }

    /**
     * @param  list<array{address: string, name: string}>  $recipients
     */
    private static function hasAddress(array $recipients, string $address): bool
    {
        foreach ($recipients as $recipient) {
            if (strcasecmp($recipient['address'], $address) === 0) {
                return true;
            }
        }

        return false;
    }

    private function namespaceLabel(): string
    {
        return $this->namespace ?? '<any>';
    }

    private static function formatSeconds(float $seconds): string
    {
        $formatted = rtrim(rtrim(number_format(max(0.0, $seconds), 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
