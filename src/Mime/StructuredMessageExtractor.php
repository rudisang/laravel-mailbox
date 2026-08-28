<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use Rudisang\Mailbox\Support\Limits;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\LogicException;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\MessagePart;
use Symfony\Component\Mime\Part\TextPart;
use Throwable;

/** @internal */
class StructuredMessageExtractor
{
    public function __construct(private readonly Limits $limits) {}

    public function extract(Email $email, string $partsDir): ExtractedMessage
    {
        $subject = $email->getSubject();
        $fromAddresses = array_values($email->getFrom());
        $toAddresses = array_values($email->getTo());
        $ccAddresses = array_values($email->getCc());
        $bccAddresses = array_values($email->getBcc());
        $replyToAddresses = array_values($email->getReplyTo());
        $from = AddressNormalizer::normalize($fromAddresses);
        $to = AddressNormalizer::normalize($toAddresses);
        $cc = AddressNormalizer::normalize($ccAddresses);
        $bcc = AddressNormalizer::normalize($bccAddresses);
        $replyTo = AddressNormalizer::normalize($replyToAddresses);
        $addressEmails = [
            ...AddressNormalizer::emails($fromAddresses),
            ...AddressNormalizer::emails($toAddresses),
            ...AddressNormalizer::emails($ccAddresses),
            ...AddressNormalizer::emails($replyToAddresses),
        ];
        [$tags, $metadata] = $this->headerFacts($email);

        /** @var list<ExtractedPart> $parts */
        $parts = [];
        $status = 'ok';
        $error = null;
        $htmlId = null;
        $textId = null;
        $decodedBytes = 0;

        try {
            $root = $email->getBody();
        } catch (Throwable $exception) {
            return new ExtractedMessage(
                $subject,
                $from,
                $to,
                $cc,
                $bcc,
                $replyTo,
                null,
                null,
                [],
                $tags,
                $metadata,
                null,
                $this->searchText($subject, $addressEmails, null),
                'failed',
                $exception instanceof LogicException
                    ? 'no_body'
                    : substr('body:'.get_debug_type($exception), 0, 120),
                0,
                0,
            );
        }

        $position = 0;
        $this->walk(
            $root,
            null,
            0,
            $position,
            $partsDir,
            $parts,
            $status,
            $error,
            $htmlId,
            $textId,
            $decodedBytes,
        );

        $bodyText = $this->bodyText($parts, $textId, $htmlId);
        $previewText = $bodyText === null ? null : mb_substr($bodyText, 0, 200);
        $attachmentCount = count(array_filter($parts, fn (ExtractedPart $part) => $part->isAttachment));

        return new ExtractedMessage(
            $subject,
            $from,
            $to,
            $cc,
            $bcc,
            $replyTo,
            $htmlId,
            $textId,
            $parts,
            $tags,
            $metadata,
            $previewText,
            $this->searchText($subject, $addressEmails, $bodyText),
            $status,
            $error,
            $attachmentCount,
            $decodedBytes,
        );
    }

    /**
     * @param  list<ExtractedPart>  $parts
     * @param  'ok'|'partial'|'failed'  $status
     */
    private function walk(
        AbstractPart $part,
        ?string $parentId,
        int $depth,
        int &$position,
        string $partsDir,
        array &$parts,
        string &$status,
        ?string &$error,
        ?string &$htmlId,
        ?string &$textId,
        int &$decodedBytes,
    ): void {
        try {
            if (count($parts) >= $this->limits->parts) {
                $status = 'partial';
                $error = 'limit:parts';

                return;
            }

            if ($depth > $this->limits->depth) {
                $status = 'partial';
                $error = 'limit:depth';

                return;
            }

            $id = (string) Str::ulid();
            $headers = $part->getPreparedHeaders();
            $contentTypeHeader = $headers->get('Content-Type');
            $contentType = $contentTypeHeader?->getBodyAsString() ?? $part->getMediaType().'/'.$part->getMediaSubtype();
            $disposition = $part instanceof TextPart ? $part->getDisposition() : null;
            $filename = $part instanceof DataPart ? $part->getFilename() : ($part instanceof TextPart ? $part->getName() : null);
            $contentId = $part instanceof DataPart && $part->hasContentId() ? $part->getContentId() : null;
            $charset = null;

            if ($contentTypeHeader instanceof ParameterizedHeader) {
                $charset = $contentTypeHeader->getParameter('charset') ?: null;
            }

            $headerBody = $headers->getHeaderBody('Content-Transfer-Encoding');
            $transferEncoding = ! $part instanceof MessagePart && is_string($headerBody) ? $headerBody : null;
            $isInline = $disposition === 'inline' && $contentId !== null;
            $isAttachment = $disposition === 'attachment'
                || ($disposition === 'inline' && $contentId === null && $part instanceof DataPart);
            $partPosition = $position++;

            if ($part instanceof AbstractMultipartPart) {
                $parts[] = new ExtractedPart(
                    $id,
                    $parentId,
                    $partPosition,
                    $depth,
                    $contentType,
                    $part->getMediaType(),
                    $part->getMediaSubtype(),
                    $disposition,
                    $filename,
                    $contentId,
                    $charset,
                    $transferEncoding,
                    0,
                    null,
                    $isInline,
                    $isAttachment,
                    null,
                );

                foreach ($part->getParts() as $child) {
                    $this->walk(
                        $child,
                        $id,
                        $depth + 1,
                        $position,
                        $partsDir,
                        $parts,
                        $status,
                        $error,
                        $htmlId,
                        $textId,
                        $decodedBytes,
                    );
                }

                return;
            }

            $blobPath = $partsDir.DIRECTORY_SEPARATOR.$id.'.bin';
            $result = PartWriter::write($part, $blobPath);
            $parts[] = new ExtractedPart(
                $id,
                $parentId,
                $partPosition,
                $depth,
                $contentType,
                $part->getMediaType(),
                $part->getMediaSubtype(),
                $disposition,
                $filename,
                $contentId,
                $charset,
                $transferEncoding,
                $result['bytes'],
                $result['sha256'],
                $isInline,
                $isAttachment,
                $blobPath,
            );
            $decodedBytes += $result['bytes'];

            if ($part->getMediaType() === 'text' && $part->getMediaSubtype() === 'html' && ! $isAttachment && $htmlId === null) {
                $htmlId = $id;
            }

            if ($part->getMediaType() === 'text' && $part->getMediaSubtype() === 'plain' && ! $isAttachment && $textId === null) {
                $textId = $id;
            }
        } catch (Throwable $exception) {
            $status = $parts === [] ? 'failed' : 'partial';
            $error = substr('part:'.get_debug_type($exception), 0, 120);
        }
    }

    /**
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function headerFacts(Email $email): array
    {
        $tags = [];
        $metadata = [];

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader) {
                $tags[] = $header->getValue();

                continue;
            }

            if ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();

                continue;
            }

            if (! $header instanceof UnstructuredHeader) {
                continue;
            }

            if (strcasecmp($header->getName(), 'X-Tag') === 0) {
                $tags[] = $header->getValue();

                continue;
            }

            if (str_starts_with(strtolower($header->getName()), 'x-metadata-')) {
                $metadata[substr($header->getName(), strlen('X-Metadata-'))] = $header->getValue();
            }
        }

        return [$tags, $metadata];
    }

    /**
     * @param  list<ExtractedPart>  $parts
     */
    private function bodyText(array $parts, ?string $textId, ?string $htmlId): ?string
    {
        $partId = $textId ?? $htmlId;

        if ($partId === null) {
            return null;
        }

        $part = null;

        foreach ($parts as $candidate) {
            if ($candidate->id === $partId) {
                $part = $candidate;

                break;
            }
        }

        if ($part?->blobPath === null) {
            return null;
        }

        $readLimit = max(1, $textId !== null
            ? max($this->limits->searchTextBytes, 800)
            : $this->limits->previewBytes);
        $contents = file_get_contents($part->blobPath, false, null, 0, $readLimit);

        if ($contents === false) {
            return null;
        }

        $contents = Charset::toUtf8($contents, $part->charset);

        if ($textId === null) {
            $contents = $this->htmlBodyText($contents);
        }

        $collapsed = preg_replace('/\s+/u', ' ', $contents);

        return trim($collapsed ?? $contents);
    }

    private function htmlBodyText(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_BIGLINES);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//style | //script | //noscript | //template') ?: []) as $element) {
            if (! $element instanceof DOMNode) {
                continue;
            }

            $element->parentNode?->removeChild($element);
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        return $body === null ? '' : $body->textContent;
    }

    /**
     * @param  list<string>  $addressEmails
     */
    private function searchText(?string $subject, array $addressEmails, ?string $bodyText): ?string
    {
        $values = array_filter(
            [$subject, ...$addressEmails, $bodyText],
            fn (?string $value) => $value !== null && $value !== '',
        );

        if ($values === []) {
            return null;
        }

        $bounded = substr(implode(' ', $values), 0, $this->limits->searchTextBytes);
        $validUtf8 = mb_convert_encoding($bounded, 'UTF-8', 'UTF-8');

        return mb_strtolower(mb_strcut($validUtf8, 0, $this->limits->searchTextBytes, 'UTF-8'), 'UTF-8');
    }
}
