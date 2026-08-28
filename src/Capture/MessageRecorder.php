<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Capture;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Rudisang\Mailbox\Events\MessageCaptured;
use Rudisang\Mailbox\Exceptions\CaptureFailedException;
use Rudisang\Mailbox\Mime\AddressNormalizer;
use Rudisang\Mailbox\Mime\ExtractedMessage;
use Rudisang\Mailbox\Mime\StructuredMessageExtractor;
use Rudisang\Mailbox\Storage\MaintenanceLock;
use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\PartRecord;
use Rudisang\Mailbox\Storage\Pruner;
use Rudisang\Mailbox\Support\Limits;
use Rudisang\Mailbox\Support\StoragePaths;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Throwable;

final class MessageRecorder
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly MessageStore $store,
        private readonly StructuredMessageExtractor $extractor,
        private readonly ContextCollector $context,
        private readonly Limits $limits,
        private readonly MaintenanceLock $lock,
        private readonly Pruner $pruner,
        private readonly Dispatcher $events,
        private readonly FailureInjector $failures,
    ) {}

    public function record(SentMessage $sent, ?string $mailer): MessageRecord
    {
        $id = (string) Str::ulid();
        $tmp = $this->paths->tmp($id);
        $final = $this->paths->message($id);

        try {
            $this->paths->ensureRoot();

            if (! @mkdir($tmp.DIRECTORY_SEPARATOR.'parts', 0700, true) && ! is_dir($tmp.DIRECTORY_SEPARATOR.'parts')) {
                throw new RuntimeException('Unable to create the capture staging directory.');
            }

            $this->failures->check('before_raw_write');
            $raw = RawStreamWriter::write(
                $sent->toIterable(),
                $tmp.DIRECTORY_SEPARATOR.'raw.eml',
                $this->limits->rawBytes,
            );
            $this->failures->check('after_raw_write');

            $original = $sent->getOriginalMessage();
            $envelope = $sent->getEnvelope();
            $rawHeaders = RawHeaderBlock::read(
                $tmp.DIRECTORY_SEPARATOR.'raw.eml',
                $this->limits->headerBytes,
            );

            if ($original instanceof Email) {
                try {
                    $extracted = $this->extractor->extract($original, $tmp.DIRECTORY_SEPARATOR.'parts');
                } catch (Throwable $exception) {
                    $extracted = ExtractedMessage::failed('extract:'.get_debug_type($exception));
                }
            } else {
                $extracted = ExtractedMessage::unsupported();
            }
            $this->failures->check('after_extract');

            $context = $this->context->take($original, $mailer);
            $record = $this->buildRecord(
                $id,
                $sent,
                $envelope,
                $raw,
                $rawHeaders,
                $extracted,
                $mailer,
                $context,
            );
            $parts = $this->buildParts($id, $extracted);

            // Ensure schema creation's exclusive lock is complete before taking the capture lock.
            $this->store->pdo();

            $seq = $this->lock->shared(function () use ($tmp, $final, $record, $parts): int {
                if (! @rename($tmp, $final)) {
                    throw new RuntimeException('Unable to move the capture into place.');
                }

                try {
                    $this->failures->check('after_rename');
                    $this->failures->check('before_commit');

                    return $this->store->insert($record, $parts);
                } catch (Throwable $exception) {
                    MessageStore::removeDirectory($final);

                    throw $exception;
                }
            });

            if (! is_int($seq)) {
                throw new RuntimeException('Unable to acquire the mailbox capture lock.');
            }
        } catch (TransportException $exception) {
            MessageStore::removeDirectory($tmp);

            throw $exception;
        } catch (Throwable $exception) {
            MessageStore::removeDirectory($tmp);

            throw CaptureFailedException::wrap($exception);
        }

        $record = $record->withSeq($seq);

        try {
            $this->failures->check('after_commit');
            $this->events->dispatch(new MessageCaptured($id, $seq, $record->namespace));
            $this->failures->check('in_event');
        } catch (Throwable) {
            // Listeners can never invalidate a committed capture.
        }

        try {
            $this->failures->check('in_prune');
            $this->pruner->prune(false);
        } catch (Throwable) {
            // Pruning is best-effort after capture.
        }

        return $record;
    }

    /**
     * @param  array{bytes: int, sha256: string}  $raw
     * @param  list<array{0: string, 1: string}>  $rawHeaders
     * @param  array<string, string|null>  $context
     */
    private function buildRecord(
        string $id,
        SentMessage $sent,
        Envelope $envelope,
        array $raw,
        array $rawHeaders,
        ExtractedMessage $extracted,
        ?string $mailer,
        array $context,
    ): MessageRecord {
        try {
            $messageId = $sent->getMessageId();
        } catch (Throwable) {
            $messageId = null;
        }

        return new MessageRecord(
            null,
            $id,
            gmdate('Y-m-d\TH:i:s\Z'),
            $messageId,
            $raw['sha256'],
            $raw['bytes'],
            $mailer,
            $extracted->parseStatus,
            $extracted->parseError,
            $extracted->parseStatus === 'unsupported'
                ? RawHeaderBlock::first($rawHeaders, 'Subject')
                : $extracted->subject,
            $extracted->from,
            $extracted->to,
            $extracted->cc,
            $extracted->bcc,
            $extracted->replyTo,
            $envelope->getSender()->getAddress(),
            AddressNormalizer::emails(array_values($envelope->getRecipients())),
            $extracted->tags,
            $extracted->metadata,
            $rawHeaders,
            $extracted->htmlPartId !== null,
            $extracted->textPartId !== null,
            $extracted->previewText,
            $extracted->searchText,
            count($extracted->parts),
            $extracted->attachmentCount,
            $extracted->decodedBytes,
            null,
            $this->context->namespace(),
            $context,
        );
    }

    /** @return list<PartRecord> */
    private function buildParts(string $messageId, ExtractedMessage $extracted): array
    {
        $records = [];

        foreach ($extracted->parts as $part) {
            $records[] = new PartRecord(
                $part->id,
                $messageId,
                $part->parentId,
                $part->position,
                $part->depth,
                $part->contentType,
                $part->mediaType,
                $part->mediaSubtype,
                $part->disposition,
                $part->filename,
                $part->contentId,
                $part->charset,
                $part->transferEncoding,
                $part->decodedBytes,
                $part->sha256,
                $part->isInline,
                $part->isAttachment,
            );
        }

        return $records;
    }
}
