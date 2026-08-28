@php($mailboxTime = function (string $value): string {
    $moment = \Illuminate\Support\Carbon::parse($value);

    if ($moment->isToday()) {
        return 'Today '.$moment->format('H:i');
    }

    if ($moment->isYesterday()) {
        return 'Yesterday '.$moment->format('H:i');
    }

    return $moment->isCurrentYear()
        ? $moment->format('j M H:i')
        : $moment->format('j M Y');
})

@php($activeFilters = ($filters['q'] ?? '') !== '' || ($filters['unread'] ?? false) || ($filters['attachments'] ?? false) || ($filters['issues'] ?? false))

<div class="mb-list-head">
    <span>{{ count($messages) }} {{ \Illuminate\Support\Str::plural('message', count($messages)) }}</span>
    @if ($activeFilters)
        <a href="{{ route('mailbox.inbox') }}">Clear filters</a>
    @endif
</div>

<ol class="mb-list" role="list">
    @forelse ($messages as $m)
        @php($sender = $m->from[0] ?? ['name' => '', 'address' => 'Unknown sender'])
        <li>
            <a href="{{ route('mailbox.message', ['id' => $m->id]) }}"
               data-message="{{ $m->id }}"
               @class(['mb-row', 'is-unread' => ! $m->isRead(), 'is-selected' => $selectedId === $m->id])
               aria-current="{{ $selectedId === $m->id ? 'true' : 'false' }}">
                <span class="mb-row__dot" aria-hidden="true"></span>

                <span class="mb-row__top">
                    <span class="mb-row__from mb-truncate">{{ $sender['name'] !== '' ? $sender['name'] : $sender['address'] }}</span>
                    <time class="mb-row__time" datetime="{{ $m->capturedAt }}">{{ $mailboxTime($m->capturedAt) }}</time>
                </span>

                <span class="mb-row__subject">{{ $m->subject ?? '(No subject)' }}</span>
                <span class="mb-row__preview">{{ $m->previewText ?? 'No preview available' }}</span>

                <span class="mb-row__tags">
                    @if ($m->attachmentCount > 0)
                        <span class="mb-tag">
                            <svg class="mb-i mb-i-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5 12.3 19a4.6 4.6 0 0 1-6.5-6.5l7.7-7.6a3 3 0 0 1 4.3 4.3l-7.7 7.6a1.5 1.5 0 0 1-2.1-2.1l7-7"/></svg>
                            {{ $m->attachmentCount }}<span class="mb-visually-hidden">&nbsp;attachments</span>
                        </span>
                    @endif
                    @if ($m->parseStatus !== 'ok')
                        <span class="mb-tag {{ $m->parseStatus === 'failed' ? 'mb-tag--danger' : 'mb-tag--warn' }} mb-parse-badge">{{ $m->parseStatus }}<span class="mb-visually-hidden">&nbsp;parse status</span></span>
                    @endif
                </span>
            </a>
        </li>
    @empty
        <li class="mb-list-empty">
            @if ($activeFilters)
                No messages match these filters.
            @else
                Nothing captured yet.
            @endif
        </li>
    @endforelse
</ol>
