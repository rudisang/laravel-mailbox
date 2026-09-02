<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Security;

/** @internal */
final readonly class PreviewResult
{
    /**
     * @param  list<array{text: string, url: string, openable: bool}>  $links
     * @param  list<string>  $remoteImages
     * @param  array<string, int>  $removed
     */
    public function __construct(
        public string $document,
        public array $links,
        public array $remoteImages,
        public int $trackingPixels,
        public array $removed,
        public bool $truncated,
    ) {}
}
