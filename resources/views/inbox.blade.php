@extends('mailbox::layout')

@section('content')
    @php($listQuery = array_filter([
        'q' => $filters['q'],
        'unread' => $filters['unread'] ?: null,
        'attachments' => $filters['attachments'] ?: null,
        'issues' => $filters['issues'] ?: null,
    ]))

    <section id="mailbox-list" class="mb-pane mb-pane--list mb-scroll" aria-label="Messages">
        @include('mailbox::partials.list', ['selectedId' => $selectedId])
    </section>

    <div class="mb-pane mb-pane--detail">
        <a class="mb-back" href="{{ route('mailbox.inbox', $listQuery) }}" data-back>
            <svg class="mb-i mb-i-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H6"/><path d="m12 5-7 7 7 7"/></svg>
            All messages
        </a>

        <section id="mailbox-detail" class="mb-detail-scroll mb-scroll" aria-label="Message">
            @if ($detail)
                @include('mailbox::partials.detail', ['detail' => $detail])
            @else
                @include('mailbox::partials.empty')
            @endif
        </section>
    </div>
@endsection
