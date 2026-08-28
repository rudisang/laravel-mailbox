<!doctype html>
<html lang="en" data-theme="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Message not found — Mailbox</title>
    <link rel="stylesheet" href="{{ \Rudisang\Mailbox\Support\Assets::url('mailbox.css') }}">
</head>
<body>
    <header class="mb-topbar">
        <a href="{{ route('mailbox.inbox') }}" class="mb-brand">
            <span class="mb-brand__mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5 12 13l9-5.5"/><rect x="3" y="5" width="18" height="14" rx="2.5"/></svg>
            </span>
            Mailbox
        </a>
    </header>

    <main class="mb-404">
        <p class="mb-404__code">404</p>
        <h1>Message not found</h1>
        <p>It was deleted, cleared, or it never reached this mailbox.</p>
        <a class="mb-btn mb-btn--primary" href="{{ route('mailbox.inbox') }}">Back to Mailbox</a>
    </main>
    <script type="module" src="{{ \Rudisang\Mailbox\Support\Assets::url('mailbox.js') }}"></script>
</body>
</html>
