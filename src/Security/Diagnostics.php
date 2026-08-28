<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Security;

use Rudisang\Mailbox\Storage\MessageRecord;
use Rudisang\Mailbox\Storage\PartRecord;

final class Diagnostics
{
    /** @var non-empty-string */
    public const RULES_VERSION = '2026.08.1';

    /**
     * @param  list<PartRecord>  $parts
     * @return array{rules_version: string, capture_id: string, namespace: ?string, results: list<array{rule: string, severity: 'info'|'warning'|'error', message: string, evidence: mixed}>}
     */
    public static function evaluate(MessageRecord $record, array $parts, ?PreviewResult $preview, ?int $htmlBytes): array
    {
        $results = [];

        if ($record->parseStatus !== 'ok') {
            self::add(
                $results,
                'parse.status',
                $record->parseStatus === 'failed' ? 'error' : 'warning',
                'Message parsing did not complete normally.',
                ['status' => $record->parseStatus, 'error' => $record->parseError],
            );
        }

        if ($record->parseError !== null && str_contains($record->parseError, 'limit:')) {
            self::add($results, 'limits.hit', 'warning', 'A capture limit was reached.', [$record->parseError]);
        }

        foreach ($record->rawHeaders as $header) {
            if (strcasecmp($header[0], 'Bcc') === 0) {
                self::add($results, 'raw.bcc_present', 'error', 'A Bcc header remains in the raw message.', ['header' => 'Bcc']);

                break;
            }
        }

        if ($preview !== null) {
            self::addRemovedRule($results, $preview, 'script', 'html.scripts_removed', 'Script elements were removed.');
            self::addRemovedRule($results, $preview, 'form', 'html.forms_removed', 'Form elements were removed.');
            self::addRemovedRule($results, $preview, 'iframe', 'html.iframes_removed', 'Iframe elements were removed.');

            $objects = ($preview->removed['object'] ?? 0) + ($preview->removed['embed'] ?? 0);

            if ($objects > 0) {
                self::add($results, 'html.objects_removed', 'warning', 'Object elements were removed.', $objects);
            }

            if ($preview->trackingPixels > 0) {
                self::add($results, 'html.tracking_pixels', 'warning', 'Tracking pixels were blocked.', $preview->trackingPixels);
            }

            if ($preview->remoteImages !== []) {
                self::add($results, 'html.remote_images', 'info', 'Remote images were blocked.', $preview->remoteImages);
            }

            if ($preview->links !== []) {
                self::add($results, 'links.neutralised', 'info', 'Links were neutralised in the preview.', count($preview->links));
            }
        }

        if ($record->hasHtml && ! $record->hasText) {
            self::add($results, 'html.no_text_alternative', 'warning', 'The message has no text alternative.', false);
        }

        if ($htmlBytes !== null && $htmlBytes > 102 * 1024) {
            self::add($results, 'html.gmail_clipping', 'info', 'The HTML may be clipped by Gmail.', $htmlBytes);
        }

        if ($record->subject === null || trim($record->subject) === '') {
            self::add($results, 'message.no_subject', 'warning', 'The message has no subject.', false);
        }

        if ($record->to === [] && $record->cc === [] && $record->bcc === [] && $record->envelopeRecipients === []) {
            self::add($results, 'message.no_recipients', 'warning', 'The message has no recipients.', false);
        }

        return [
            'rules_version' => self::RULES_VERSION,
            'capture_id' => $record->id,
            'namespace' => $record->namespace,
            'results' => $results,
        ];
    }

    /**
     * @param  list<array{rule: string, severity: 'info'|'warning'|'error', message: string, evidence: mixed}>  $results
     */
    private static function addRemovedRule(array &$results, PreviewResult $preview, string $key, string $rule, string $message): void
    {
        $count = $preview->removed[$key] ?? 0;

        if ($count > 0) {
            self::add($results, $rule, 'warning', $message, $count);
        }
    }

    /**
     * @param  list<array{rule: string, severity: 'info'|'warning'|'error', message: string, evidence: mixed}>  $results
     * @param  'info'|'warning'|'error'  $severity
     */
    private static function add(array &$results, string $rule, string $severity, string $message, mixed $evidence): void
    {
        $results[] = [
            'rule' => $rule,
            'severity' => $severity,
            'message' => $message,
            'evidence' => $evidence,
        ];
    }
}
