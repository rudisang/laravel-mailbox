@extends('mailbox::layout')

@section('content')
    <section id="mailbox-list" aria-label="Messages">
        @include('mailbox::partials.list', ['selectedId' => $selectedId])
    </section>

    <section id="mailbox-detail" aria-label="Message">
        @if ($detail)
            @include('mailbox::partials.detail', ['detail' => $detail])
        @else
            @include('mailbox::partials.empty')
        @endif
    </section>
@endsection
