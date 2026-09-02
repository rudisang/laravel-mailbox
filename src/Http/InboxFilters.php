<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Http;

use Illuminate\Http\Request;

/** @internal */
final readonly class InboxFilters
{
    public function __construct(
        public ?string $q,
        public ?bool $unread,
        public ?bool $attachments,
        public ?bool $issues,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $query = $request->query('q');

        return new self(
            is_string($query) ? mb_substr(trim($query), 0, 200) : '',
            self::boolean($request->query('unread')),
            self::boolean($request->query('attachments')),
            self::boolean($request->query('issues')),
        );
    }

    /** @return array{q: ?string, unread: ?bool, attachments: ?bool, issues: ?bool} */
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'unread' => $this->unread,
            'attachments' => $this->attachments,
            'issues' => $this->issues,
        ];
    }

    /** @return array<string, bool|string> */
    public function toQuery(): array
    {
        return array_filter(
            $this->toArray(),
            static fn (bool|string|null $value): bool => $value !== null && $value !== false && $value !== '',
        );
    }

    private static function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
