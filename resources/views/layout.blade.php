<!doctype html>
<html lang="en" data-theme="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mailbox</title>
    <link rel="stylesheet" href="{{ \Rudisang\Mailbox\Support\Assets::url('mailbox.css') }}">
</head>
<body data-mailbox-base="{{ $basePath }}" data-mailbox-seq="{{ $status['seq'] }}">
    <header class="mb-topbar">
        <a href="{{ route('mailbox.inbox') }}" class="mb-brand">Mailbox</a>

        <form method="GET" action="{{ route('mailbox.inbox') }}" data-search role="search">
            <label for="mailbox-search">Search messages</label>
            <input id="mailbox-search" type="search" name="q" value="{{ $filters['q'] }}" maxlength="200" placeholder="Search">
        </form>

        <nav aria-label="Message filters">
            <a href="{{ route('mailbox.inbox', array_filter(['q' => $filters['q'], 'unread' => $filters['unread'] ? null : 1])) }}" aria-pressed="{{ $filters['unread'] ? 'true' : 'false' }}">Unread</a>
            <a href="{{ route('mailbox.inbox', array_filter(['q' => $filters['q'], 'attachments' => $filters['attachments'] ? null : 1])) }}" aria-pressed="{{ $filters['attachments'] ? 'true' : 'false' }}">Attachments</a>
            <a href="{{ route('mailbox.inbox', array_filter(['q' => $filters['q'], 'issues' => $filters['issues'] ? null : 1])) }}" aria-pressed="{{ $filters['issues'] ? 'true' : 'false' }}">Issues</a>
        </nav>

        <span id="mailbox-unread" aria-label="Unread messages">{{ $status['unread'] }}</span>

        <form method="POST" action="{{ route('mailbox.clear') }}" data-action="clear">
            @csrf
            <button type="submit">Clear all</button>
        </form>

        <button type="button" data-theme-toggle aria-label="Change colour theme">Theme</button>
        <button type="button" data-shortcuts-help aria-label="Show keyboard shortcuts">Shortcuts</button>
    </header>

    <main class="mb-shell">
        @yield('content')
    </main>

    <div id="mailbox-live" aria-live="polite" class="mb-visually-hidden"></div>
    <script type="module" src="{{ \Rudisang\Mailbox\Support\Assets::url('mailbox.js') }}"></script>
</body>
</html>
