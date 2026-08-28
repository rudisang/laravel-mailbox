@php($record = $detail->record)

@php($mailboxBytes = function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes.' B';
    }

    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1).' KB';
    }

    return number_format($bytes / 1048576, 1).' MB';
})

@php($mailboxAddress = fn (array $address): string => $address['name'] !== '' ? $address['name'].' <'.$address['address'].'>' : $address['address'])

@php($issueCount = count(array_filter($detail->diagnostics['results'], fn (array $result): bool => $result['severity'] !== 'info')))

<article class="mb-detail" data-detail-root data-viewport-mode="full">
    <div class="mb-card">
        <header class="mb-detail__head">
            <div class="mb-detail__titlerow">
                <h1 class="mb-detail__subject" tabindex="-1" data-detail-title>{{ $record->subject ?? '(No subject)' }}</h1>

                @if ($record->parseStatus !== 'ok')
                    <span class="mb-tag {{ $record->parseStatus === 'failed' ? 'mb-tag--danger' : 'mb-tag--warn' }} mb-parse-badge">{{ $record->parseStatus }}<span class="mb-visually-hidden">&nbsp;parse status</span></span>
                @endif
            </div>

            <div class="mb-addresses">
                <div class="mb-addr">
                    <span class="mb-addr__label" id="mb-addr-from">From</span>
                    <span class="mb-addr__values" role="list" aria-labelledby="mb-addr-from">
                        @forelse ($record->from as $address)
                            <span class="mb-address" role="listitem">{{ $mailboxAddress($address) }}</span>
                        @empty
                            <span class="mb-address" role="listitem">Unknown sender</span>
                        @endforelse
                    </span>
                </div>

                <div class="mb-addr">
                    <span class="mb-addr__label" id="mb-addr-to">To</span>
                    <span class="mb-addr__values" role="list" aria-labelledby="mb-addr-to">
                        @forelse ($record->to as $address)
                            <span class="mb-address" role="listitem">{{ $mailboxAddress($address) }}</span>
                        @empty
                            <span class="mb-address" role="listitem">None</span>
                        @endforelse
                    </span>
                </div>

                @if ($record->cc !== [])
                    <div class="mb-addr">
                        <span class="mb-addr__label" id="mb-addr-cc">Cc</span>
                        <span class="mb-addr__values" role="list" aria-labelledby="mb-addr-cc">
                            @foreach ($record->cc as $address)
                                <span class="mb-address" role="listitem">{{ $mailboxAddress($address) }}</span>
                            @endforeach
                        </span>
                    </div>
                @endif

                @if ($record->bcc !== [])
                    <div class="mb-addr">
                        <span class="mb-addr__label" id="mb-addr-bcc">Bcc</span>
                        <span class="mb-addr__values" role="list" aria-labelledby="mb-addr-bcc">
                            @foreach ($record->bcc as $address)
                                <span class="mb-address" role="listitem">{{ $mailboxAddress($address) }}</span>
                            @endforeach
                        </span>
                    </div>
                @endif
            </div>

            <dl class="mb-meta">
                <div><dt>Captured</dt><dd><time datetime="{{ $record->capturedAt }}">{{ \Illuminate\Support\Carbon::parse($record->capturedAt)->format('j M Y H:i:s') }}</time></dd></div>
                <div><dt>Size</dt><dd>{{ $mailboxBytes($record->rawBytes) }}</dd></div>
                <div><dt>Mailer</dt><dd>{{ $record->mailer ?? 'Unknown' }}</dd></div>
                <div><dt>Namespace</dt><dd class="mb-mono">{{ $record->namespace ?? 'None' }}</dd></div>
                <div><dt>Message-ID</dt><dd class="mb-mono">{{ $record->messageId ?? 'None' }}</dd></div>
            </dl>
        </header>

        <div class="mb-toolbar mb-toolbar--card" role="group" aria-label="Message actions">
            <form method="POST" action="{{ $detail->urls['read'] }}" data-action="read">
                @csrf
                <input type="hidden" name="read" value="{{ $detail->record->isRead() ? '0' : '1' }}">
                <button type="submit" class="mb-btn mb-btn--outline mb-btn--sm">
                    <span data-read-label>{{ $detail->record->isRead() ? 'Mark unread' : 'Mark read' }}</span>
                </button>
            </form>

            <a class="mb-btn mb-btn--outline mb-btn--sm" href="{{ $detail->urls['download'] }}" download>
                <svg class="mb-i mb-i-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11"/><path d="m7.5 11 4.5 4.5 4.5-4.5"/><path d="M5 19h14"/></svg>
                Download .eml
            </a>

            <span class="mb-toolbar__spacer"></span>

            <form method="POST" action="{{ $detail->urls['destroy'] }}" data-action="delete">
                @csrf
                @method('DELETE')
                <button type="submit" class="mb-btn mb-btn--danger mb-btn--sm">
                    <svg class="mb-i mb-i-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14"/><path d="M9.5 7V5.5h5V7"/><path d="M7 7l.8 12h8.4L17 7"/></svg>
                    Delete
                </button>
            </form>
        </div>
    </div>

    <div class="mb-tabbar">
        <div class="mb-tablist mb-scroll" role="tablist" aria-label="Message details">
            <button type="button" class="mb-seg" role="tab" data-tab="html" id="tab-btn-html" aria-controls="tab-html" aria-selected="true" tabindex="0">HTML</button>
            <button type="button" class="mb-seg" role="tab" data-tab="text" id="tab-btn-text" aria-controls="tab-text" aria-selected="false" tabindex="-1">Text</button>
            <button type="button" class="mb-seg" role="tab" data-tab="headers" id="tab-btn-headers" aria-controls="tab-headers" aria-selected="false" tabindex="-1">Headers</button>
            <button type="button" class="mb-seg" role="tab" data-tab="envelope" id="tab-btn-envelope" aria-controls="tab-envelope" aria-selected="false" tabindex="-1">Envelope</button>
            <button type="button" class="mb-seg" role="tab" data-tab="mime" id="tab-btn-mime" aria-controls="tab-mime" aria-selected="false" tabindex="-1">MIME</button>
            <button type="button" class="mb-seg" role="tab" data-tab="raw" id="tab-btn-raw" aria-controls="tab-raw" aria-selected="false" tabindex="-1">Raw</button>
            <button type="button" class="mb-seg" role="tab" data-tab="attachments" id="tab-btn-attachments" aria-controls="tab-attachments" aria-selected="false" tabindex="-1">Attachments @if (count($detail->attachments) > 0)<span class="mb-seg__count">{{ count($detail->attachments) }}</span>@endif</button>
            <button type="button" class="mb-seg" role="tab" data-tab="links" id="tab-btn-links" aria-controls="tab-links" aria-selected="false" tabindex="-1">Links @if (count($detail->links) > 0)<span class="mb-seg__count">{{ count($detail->links) }}</span>@endif</button>
            <button type="button" class="mb-seg" role="tab" data-tab="diagnostics" id="tab-btn-diagnostics" aria-controls="tab-diagnostics" aria-selected="false" tabindex="-1">Diagnostics @if ($issueCount > 0)<span class="mb-seg__count mb-seg__count--warn">{{ $issueCount }}</span>@endif</button>
        </div>

        <div class="mb-segmented" role="group" aria-label="Preview viewport" data-viewport-group>
            <button type="button" class="mb-seg" data-viewport="375" aria-pressed="false">Phone <span class="mb-visually-hidden">375 pixels</span><span aria-hidden="true">375</span></button>
            <button type="button" class="mb-seg" data-viewport="768" aria-pressed="false">Tablet <span class="mb-visually-hidden">768 pixels</span><span aria-hidden="true">768</span></button>
            <button type="button" class="mb-seg" data-viewport="full" aria-pressed="true">Desktop <span aria-hidden="true">100%</span><span class="mb-visually-hidden">full width</span></button>
        </div>
    </div>

    <section class="mb-panel" role="tabpanel" id="tab-html" aria-labelledby="tab-btn-html" tabindex="0">
        <h2 class="mb-panel__title">HTML preview</h2>

        @if ($issueCount > 0)
            <p class="mb-notice">This preview is sanitised and sandboxed. {{ $issueCount }} {{ \Illuminate\Support\Str::plural('finding', $issueCount) }} in Diagnostics.</p>
        @endif

        @if ($detail->hasHtml)
            <div class="mb-device">
                <div class="mb-device__screen">
                    <iframe sandbox src="{{ $detail->urls['previewHtml'] }}" title="HTML preview" class="mb-preview" data-preview loading="lazy"></iframe>
                </div>
            </div>
        @else
            <p class="mb-panel__note">No HTML body.</p>
        @endif
    </section>

    <section class="mb-panel" role="tabpanel" id="tab-text" aria-labelledby="tab-btn-text" tabindex="0">
        <h2 class="mb-panel__title">Text body</h2>

        @if ($detail->text !== null)
            <div class="mb-device">
                <div class="mb-device__screen">
                    <iframe sandbox src="{{ $detail->urls['previewText'] }}" title="Text preview" class="mb-preview" data-preview loading="lazy"></iframe>
                </div>
            </div>
        @else
            <p class="mb-panel__note">No text body.</p>
        @endif
    </section>

    <section class="mb-panel" role="tabpanel" id="tab-headers" aria-labelledby="tab-btn-headers" tabindex="0">
        <h2 class="mb-panel__title">Headers</h2>

        <div class="mb-table-wrap mb-scroll">
            <table class="mb-table">
                <thead><tr><th scope="col">Header</th><th scope="col">Value</th></tr></thead>
                <tbody>
                    @foreach ($record->rawHeaders as [$name, $value])
                        <tr><th scope="row">{{ $name }}</th><td class="mb-mono">{{ $value }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mb-panel" role="tabpanel" id="tab-envelope" aria-labelledby="tab-btn-envelope" tabindex="0">
        <h2 class="mb-panel__title">Envelope</h2>

        <dl class="mb-kv">
            <dt>Envelope sender</dt><dd class="mb-mono">{{ $record->envelopeSender ?? 'None' }}</dd>
            <dt>Envelope recipients</dt><dd class="mb-mono">{{ $record->envelopeRecipients === [] ? 'None' : implode(', ', $record->envelopeRecipients) }}</dd>
            <dt>Original To</dt><dd class="mb-mono">{{ $record->to === [] ? 'None' : implode(', ', array_column($record->to, 'address')) }}</dd>
            <dt>Original Cc</dt><dd class="mb-mono">{{ $record->cc === [] ? 'None' : implode(', ', array_column($record->cc, 'address')) }}</dd>
            <dt>Original Bcc</dt><dd class="mb-mono">{{ $record->bcc === [] ? 'None' : implode(', ', array_column($record->bcc, 'address')) }}</dd>
            <dt>Reply-To</dt><dd class="mb-mono">{{ $record->replyTo === [] ? 'None' : implode(', ', array_column($record->replyTo, 'address')) }}</dd>
        </dl>
    </section>

    <section class="mb-panel" role="tabpanel" id="tab-mime" aria-labelledby="tab-btn-mime" tabindex="0">
        <h2 class="mb-panel__title">MIME structure</h2>

        <ol class="mb-mime-tree" role="list">
            @foreach ($detail->mimeTree as $node)
                <li data-depth="{{ $node['depth'] }}">{{ $node['label'] }}</li>
            @endforeach
        </ol>
    </section>

    <section class="mb-panel" role="tabpanel" id="tab-raw" aria-labelledby="tab-btn-raw" tabindex="0">
        <h2 class="mb-panel__title">Raw message</h2>

        <pre class="mb-code mb-scroll">{{ \Illuminate\Support\Str::limit($raw ?? '', 256 * 1024) }}</pre>
        <p class="mb-panel__foot"><a class="mb-btn mb-btn--outline mb-btn--sm" href="{{ $detail->urls['raw'] }}">View full raw message</a></p>
    </section>

    <section class="mb-panel" role="tabpanel" id="tab-attachments" aria-labelledby="tab-btn-attachments" tabindex="0">
        <h2 class="mb-panel__title">Attachments</h2>

        <ol class="mb-attachments" role="list">
            @forelse ($detail->attachments as $attachment)
                <li class="mb-attachment">
                    @if ($attachment['inlineable'])
                        <img class="mb-attachment__thumb" src="{{ $attachment['url'] }}" alt="Preview of {{ $attachment['filename'] }}" loading="lazy">
                    @endif
                    <span class="mb-attachment__name">{{ $attachment['filename'] }}</span>
                    <span class="mb-attachment__meta">{{ $attachment['part']->mediaType }}/{{ $attachment['part']->mediaSubtype }} · {{ $mailboxBytes($attachment['size']) }}</span>
                    <a class="mb-btn mb-btn--outline mb-btn--sm" href="{{ $attachment['url'] }}">Download<span class="mb-visually-hidden">&nbsp;{{ $attachment['filename'] }}</span></a>
                </li>
            @empty
                <li class="mb-panel__note">No attachments.</li>
            @endforelse
        </ol>
    </section>

    <section class="mb-panel" role="tabpanel" id="tab-links" aria-labelledby="tab-btn-links" tabindex="0">
        <h2 class="mb-panel__title">Links</h2>

        <div class="mb-table-wrap mb-scroll">
            <table class="mb-table">
                <thead><tr><th scope="col">Text</th><th scope="col">URL</th><th scope="col">Action</th></tr></thead>
                <tbody>
                    @forelse ($detail->links as $link)
                        <tr>
                            <td>{{ $link['text'] }}</td>
                            <td class="mb-mono">{{ $link['url'] }}</td>
                            <td>
                                @if ($link['openable'])
                                    <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">Open<span class="mb-visually-hidden">&nbsp;{{ $link['url'] }}&nbsp;in a new tab</span></a>
                                @else
                                    <span class="mb-tag">Not openable</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="mb-panel__note">No links.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mb-panel" role="tabpanel" id="tab-diagnostics" aria-labelledby="tab-btn-diagnostics" tabindex="0">
        <h2 class="mb-panel__title">Diagnostics</h2>

        <p class="mb-rules-version">Rules version {{ $detail->diagnostics['rules_version'] }}</p>
        <ol class="mb-diagnostics" role="list">
            @forelse ($detail->diagnostics['results'] as $result)
                <li class="mb-diagnostic" data-severity="{{ $result['severity'] }}">
                    <span class="mb-tag {{ $result['severity'] === 'error' ? 'mb-tag--danger' : ($result['severity'] === 'warning' ? 'mb-tag--warn' : '') }}">{{ $result['severity'] }}</span>
                    <span class="mb-diagnostic__msg">{{ $result['message'] }}</span>
                    <code class="mb-diagnostic__rule">{{ $result['rule'] }}</code>
                </li>
            @empty
                <li class="mb-panel__note">No diagnostics.</li>
            @endforelse
        </ol>
    </section>
</article>
