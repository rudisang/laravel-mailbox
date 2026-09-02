@php($mailboxFiltered = ($filters['q'] ?? '') !== '' || ($filters['unread'] ?? false) || ($filters['attachments'] ?? false) || ($filters['issues'] ?? false))
@php($hasMessages = count($messages ?? []) > 0 || $mailboxFiltered)

<section class="mb-empty" aria-labelledby="mailbox-empty-title">
    <span class="mb-empty__mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5 12 13l9-5.5"/><rect x="3" y="5" width="18" height="14" rx="2.5"/></svg>
    </span>

    @if ($hasMessages)
        <h1 id="mailbox-empty-title">Nothing selected</h1>
        <p>Choose a message on the left to read it, inspect its headers, MIME tree and diagnostics.</p>
    @else
        <h1 id="mailbox-empty-title">No messages yet</h1>
        <p>Every mail your app sends will land here the moment it is dispatched. Nothing leaves this machine.</p>
        <p class="mb-hint"><code>MAIL_MAILER=local</code></p>
    @endif
</section>
