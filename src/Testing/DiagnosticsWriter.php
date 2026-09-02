<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Testing;

use RuntimeException;
use XMLWriter;

/** @internal */
final class DiagnosticsWriter
{
    /** @param array<string, mixed> $report */
    public static function json(array $report): string
    {
        return json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /** @param array<string, mixed> $report */
    public static function junit(array $report): string
    {
        $captureId = $report['capture_id'] ?? '';
        $captureId = is_string($captureId) || is_int($captureId) ? (string) $captureId : '';
        $results = self::results($report['results'] ?? []);
        $tests = max(1, count($results));
        $failures = count(array_filter(
            $results,
            static fn (array $result): bool => $result['severity'] === 'error',
        ));
        $writer = new XMLWriter;

        if (! $writer->openMemory()) {
            throw new RuntimeException('Unable to initialise the JUnit diagnostics writer.');
        }

        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('testsuites');
        $writer->writeAttribute('tests', self::xmlValue((string) $tests));
        $writer->writeAttribute('failures', self::xmlValue((string) $failures));
        $writer->writeAttribute('errors', self::xmlValue('0'));
        $writer->startElement('testsuite');
        $writer->writeAttribute('name', self::xmlValue(sprintf('mailbox:%s', $captureId)));
        $writer->writeAttribute('tests', self::xmlValue((string) $tests));
        $writer->writeAttribute('failures', self::xmlValue((string) $failures));
        $writer->writeAttribute('errors', self::xmlValue('0'));

        if ($results === []) {
            $writer->startElement('testcase');
            $writer->writeAttribute('name', self::xmlValue('no-findings'));
            $writer->writeAttribute('classname', self::xmlValue('mailbox'));
            $writer->endElement();
        } else {
            foreach ($results as $result) {
                $writer->startElement('testcase');
                $writer->writeAttribute('name', self::xmlValue($result['rule']));
                $writer->writeAttribute('classname', self::xmlValue('mailbox'));

                if ($result['severity'] === 'error') {
                    $writer->startElement('failure');
                    $writer->writeAttribute('message', self::xmlValue($result['message']));
                    $writer->text(self::xmlValue(self::json($result)));
                    $writer->endElement();
                } else {
                    $writer->writeElement('system-out', self::xmlValue(self::json($result)));
                }

                $writer->endElement();
            }
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * @return list<array{rule: string, severity: string, message: string, evidence: mixed}>
     */
    private static function results(mixed $results): array
    {
        if (! is_array($results)) {
            return [];
        }

        $normalised = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $rule = $result['rule'] ?? '';
            $severity = $result['severity'] ?? 'info';
            $message = $result['message'] ?? '';

            if (! is_string($rule) || ! is_string($severity) || ! is_string($message)) {
                continue;
            }

            $normalised[] = [
                'rule' => $rule,
                'severity' => $severity,
                'message' => $message,
                'evidence' => $result['evidence'] ?? null,
            ];
        }

        return $normalised;
    }

    private static function xmlValue(string $value): string
    {
        return preg_replace('/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}]/u', '', $value) ?? '';
    }
}
