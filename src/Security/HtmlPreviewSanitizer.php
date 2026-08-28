<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Security;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Rudisang\Mailbox\Support\Limits;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** @internal */
final class HtmlPreviewSanitizer
{
    /** @var non-empty-list<non-empty-string> */
    private const STRIP_ATTRIBUTES = [
        'src',
        'srcset',
        'poster',
        'background',
        'lowsrc',
        'dynsrc',
        'ping',
        'action',
        'formaction',
        'href',
        'xlink:href',
        'data',
        'codebase',
        'archive',
        'longdesc',
        'usemap',
        'manifest',
        'profile',
    ];

    /** @var non-empty-list<non-empty-string> */
    private const COUNTED_ELEMENTS = ['script', 'form', 'iframe', 'object', 'embed', 'base', 'svg', 'math'];

    public function __construct(private readonly Limits $limits) {}

    /**
     * @param  array<string, string>  $cidMap
     */
    public function sanitize(string $html, array $cidMap, string $partUrlBase): PreviewResult
    {
        $truncated = false;

        if (strlen($html) > $this->limits->previewBytes) {
            $html = substr($html, 0, $this->limits->previewBytes);
            $truncated = true;
        }

        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        $removed = array_fill_keys([...self::COUNTED_ELEMENTS, 'meta_refresh', 'event_handlers', 'javascript_urls', 'style_blocks_kept'], 0);
        $links = [];
        $remoteImages = [];
        $trackingPixels = 0;
        $styles = [];

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.($html === '' ? '<p></p>' : $html), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_BIGLINES);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//style') ?: []) as $style) {
            if (! $style instanceof DOMElement) {
                continue;
            }

            $styles[] = $style->textContent;
            $style->parentNode?->removeChild($style);
        }

        $removed['style_blocks_kept'] = count($styles);

        foreach (iterator_to_array($xpath->query('//*[@*[starts-with(translate(name(), "ON", "on"), "on")]]') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $removed['event_handlers']++;
                }
            }
        }

        foreach (self::COUNTED_ELEMENTS as $tag) {
            foreach (iterator_to_array($xpath->query('//'.$tag) ?: []) as $element) {
                if (! $element instanceof DOMElement) {
                    continue;
                }

                $removed[$tag]++;
                $element->parentNode?->removeChild($element);
            }
        }

        foreach (iterator_to_array($xpath->query('//meta[translate(@http-equiv,"REFSH","refsh")="refresh"]') ?: []) as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }

            $removed['meta_refresh']++;
            $meta->parentNode?->removeChild($meta);
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $element->removeAttribute('data-mailbox-part');
            $element->removeAttribute('data-mailbox-data');

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);

                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attribute->name);

                    continue;
                }

                if (! in_array($name, self::STRIP_ATTRIBUTES, true)) {
                    continue;
                }

                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normalised = preg_replace('/[\x00-\x20\x7f]+/', '', $decoded) ?? '';
                $scheme = strtolower((string) parse_url($normalised, PHP_URL_SCHEME));

                if ($scheme === 'javascript' || $scheme === 'vbscript') {
                    $removed['javascript_urls']++;
                }

                if ($name === 'href' && strtolower($element->tagName) === 'a') {
                    $links[] = [
                        'text' => trim(preg_replace('/\s+/', ' ', $element->textContent) ?? ''),
                        'url' => $normalised,
                        'openable' => in_array($scheme, ['http', 'https', 'mailto'], true),
                    ];
                }

                if ($name === 'src' && strtolower($element->tagName) === 'img') {
                    if ($scheme === 'cid') {
                        $contentId = substr($decoded, 4);
                        $partId = $cidMap[$contentId] ?? null;

                        if (is_string($partId) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $partId) === 1) {
                            $element->setAttribute('data-mailbox-part', $partId);
                        }
                    } elseif ($scheme === 'data') {
                        $dataMatch = [];

                        if (preg_match('#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=\s]+)$#', $decoded, $dataMatch) === 1
                            && strlen($dataMatch[2]) <= 700000) {
                            $element->setAttribute('data-mailbox-data', preg_replace('/\s+/', '', $decoded) ?? '');
                        }
                    } elseif ($scheme === 'http' || $scheme === 'https') {
                        $remoteImages[] = $decoded;
                        $width = $element->getAttribute('width');
                        $height = $element->getAttribute('height');
                        $style = strtolower($element->getAttribute('style'));

                        if (($width !== '' && (int) $width <= 1)
                            || ($height !== '' && (int) $height <= 1)
                            || str_contains($style, 'display:none')
                            || str_contains($style, 'visibility:hidden')) {
                            $trackingPixels++;
                        }
                    }
                }

                $element->removeAttribute($attribute->name);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $inner = '';

        if ($body !== null) {
            foreach ($body->childNodes as $child) {
                $inner .= $dom->saveHTML($child);
            }
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('class', '*')
            ->allowAttribute('style', '*')
            ->allowAttribute('data-mailbox-part', 'img')
            ->allowAttribute('data-mailbox-data', 'img')
            ->allowLinkSchemes([])
            ->allowMediaSchemes([])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->withMaxInputLength($this->limits->previewBytes + 1024);
        $clean = (new HtmlSanitizer($config))->sanitize($inner);

        $out = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $out->loadHTML('<?xml encoding="UTF-8"><body>'.$clean.'</body>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $outXpath = new DOMXPath($out);

        foreach (iterator_to_array($outXpath->query('//img[@data-mailbox-part]') ?: []) as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $partId = $image->getAttribute('data-mailbox-part');
            $image->removeAttribute('data-mailbox-part');

            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $partId) === 1 && in_array($partId, $cidMap, true)) {
                $image->setAttribute('src', rtrim($partUrlBase, '/').'/'.$partId);
            }
        }

        foreach (iterator_to_array($outXpath->query('//img[@data-mailbox-data]') ?: []) as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $data = $image->getAttribute('data-mailbox-data');
            $image->removeAttribute('data-mailbox-data');

            if (preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$#', $data) === 1) {
                $image->setAttribute('src', $data);
            }
        }

        foreach (iterator_to_array($outXpath->query('//a') ?: []) as $anchor) {
            if ($anchor instanceof DOMElement) {
                $anchor->setAttribute('title', 'Link neutralised in preview — see the Links tab');
            }
        }

        $outBody = $out->getElementsByTagName('body')->item(0);
        $final = '';

        if ($outBody !== null) {
            foreach ($outBody->childNodes as $child) {
                $final .= $out->saveHTML($child);
            }
        }

        $css = str_ireplace('</style', '<\/style', implode("\n", $styles));
        $document = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>'.$css.'</style></head><body>'.$final.'</body></html>';

        return new PreviewResult(
            $document,
            $links,
            array_values(array_unique($remoteImages)),
            $trackingPixels,
            $removed,
            $truncated,
        );
    }
}
