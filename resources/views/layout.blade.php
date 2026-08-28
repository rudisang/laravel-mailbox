<!doctype html>
<html lang="en" data-theme="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="data:,">
    <title>Mailbox</title>
    <link rel="stylesheet" href="{{ \Rudisang\Mailbox\Support\Assets::url('mailbox.css') }}">
</head>
<body data-mailbox-base="{{ $basePath }}" data-mailbox-seq="{{ $status['seq'] }}">
    <header class="mb-topbar">
        <a href="{{ route('mailbox.inbox') }}" class="mb-brand">
            <span class="mb-brand__mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5 12 13l9-5.5"/><rect x="3" y="5" width="18" height="14" rx="2.5"/></svg>
            </span>
            Mailbox
        </a>

        <form method="GET" action="{{ route('mailbox.inbox') }}" data-search role="search" class="mb-search">
            <span class="mb-search__icon" aria-hidden="true">
                <svg class="mb-i mb-i-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>
            </span>
            <label class="mb-visually-hidden" for="mailbox-search">Search messages</label>
            <input id="mailbox-search" type="search" name="q" value="{{ $filters['q'] }}" maxlength="200" placeholder="Search subject, sender, body" autocomplete="off" spellcheck="false">
            <button type="submit" class="mb-search__go">
                <svg class="mb-i mb-i-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13"/><path d="m12 5 7 7-7 7"/></svg>
                <span class="mb-visually-hidden">Search</span>
            </button>
        </form>

        <nav class="mb-filters" aria-label="Message filters">
            <a class="mb-chip" href="{{ route('mailbox.inbox', array_filter(['q' => $filters['q'], 'unread' => $filters['unread'] ? null : 1, 'attachments' => $filters['attachments'] ?: null, 'issues' => $filters['issues'] ?: null])) }}" data-filter="unread" @if ($filters['unread']) aria-current="page" @endif>Unread</a>
            <a class="mb-chip" href="{{ route('mailbox.inbox', array_filter(['q' => $filters['q'], 'unread' => $filters['unread'] ?: null, 'attachments' => $filters['attachments'] ? null : 1, 'issues' => $filters['issues'] ?: null])) }}" data-filter="attachments" @if ($filters['attachments']) aria-current="page" @endif>Attachments</a>
            <a class="mb-chip" href="{{ route('mailbox.inbox', array_filter(['q' => $filters['q'], 'unread' => $filters['unread'] ?: null, 'attachments' => $filters['attachments'] ?: null, 'issues' => $filters['issues'] ? null : 1])) }}" data-filter="issues" @if ($filters['issues']) aria-current="page" @endif>Issues</a>
        </nav>

        <span class="mb-topbar__spacer"></span>

        <span id="mailbox-unread" class="mb-unread-badge" data-unread="{{ $status['unread'] }}"><span data-unread-count>{{ $status['unread'] }}</span><span class="mb-visually-hidden">&nbsp;unread</span></span>

        <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm mb-jsonly" data-theme-toggle>
            <svg class="mb-i mb-i-sm" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2M12 19.4v2M2.6 12h2M19.4 12h2M5.4 5.4l1.4 1.4M17.2 17.2l1.4 1.4M18.6 5.4l-1.4 1.4M6.8 17.2l-1.4 1.4"/></svg>
            <span class="mb-visually-hidden">Colour theme:</span>
            <span class="mb-theme-label" data-theme-label>System</span>
        </button>

        <button type="button" class="mb-btn mb-btn--ghost mb-btn--icon mb-btn--sm mb-jsonly" data-shortcuts-help aria-haspopup="dialog" aria-controls="mailbox-shortcuts">
            <svg class="mb-i mb-i-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.2 9a2.9 2.9 0 1 1 3.9 2.7c-.8.3-1.1 1-1.1 1.8v.4"/><path d="M12 17.4h.01"/><circle cx="12" cy="12" r="9.2"/></svg>
            <span class="mb-visually-hidden">Keyboard shortcuts</span>
        </button>

        <form method="POST" action="{{ route('mailbox.clear') }}" data-action="clear">
            @csrf
            <button type="submit" class="mb-btn mb-btn--danger mb-btn--sm">Clear all</button>
        </form>

        <div class="mb-progress" aria-hidden="true"></div>
    </header>

    <noscript>
        <p class="mb-noscript">JavaScript is off. Every link and form still works: messages open as full pages and each message shows all of its sections at once. Live updates, keyboard shortcuts, tabs, viewport presets and the theme switch need JavaScript.</p>
    </noscript>

    <main class="mb-shell" data-pane="{{ ($selectedId ?? null) ? 'detail' : 'list' }}">
        @yield('content')
    </main>

    <div id="mailbox-live" aria-live="polite" aria-atomic="true" class="mb-visually-hidden"></div>
    <div class="mb-toast" data-toast aria-hidden="true"></div>

    <dialog id="mailbox-shortcuts" class="mb-dialog" aria-labelledby="mailbox-shortcuts-title">
        <div class="mb-dialog__head">
            <h2 id="mailbox-shortcuts-title">Keyboard shortcuts</h2>
            <button type="button" class="mb-btn mb-btn--ghost mb-btn--icon mb-btn--sm" data-dialog-close>
                <svg class="mb-i mb-i-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                <span class="mb-visually-hidden">Close</span>
            </button>
        </div>
        <div class="mb-dialog__body">
            <dl class="mb-shortcuts">
                <div class="mb-shortcut"><dt>Next message</dt><dd><kbd>j</kbd></dd></div>
                <div class="mb-shortcut"><dt>Previous message</dt><dd><kbd>k</kbd></dd></div>
                <div class="mb-shortcut"><dt>Open message</dt><dd><kbd>Enter</kbd></dd></div>
                <div class="mb-shortcut"><dt>Focus search</dt><dd><kbd>/</kbd></dd></div>
                <div class="mb-shortcut"><dt>Delete message</dt><dd><kbd>e</kbd></dd></div>
                <div class="mb-shortcut"><dt>Toggle unread</dt><dd><kbd>u</kbd></dd></div>
                <div class="mb-shortcut"><dt>Previous tab</dt><dd><kbd>[</kbd></dd></div>
                <div class="mb-shortcut"><dt>Next tab</dt><dd><kbd>]</kbd></dd></div>
                <div class="mb-shortcut"><dt>This dialog</dt><dd><kbd>?</kbd></dd></div>
                <div class="mb-shortcut"><dt>Close / back to list</dt><dd><kbd>Esc</kbd></dd></div>
            </dl>
        </div>
    </dialog>

    <script type="module" src="{{ \Rudisang\Mailbox\Support\Assets::url('mailbox.js') }}"></script>
</body>
</html>
