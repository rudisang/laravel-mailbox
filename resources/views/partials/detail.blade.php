@php($record = $detail->record)

<article class="mb-detail-card">
    <header>
        <h1 tabindex="-1">{{ $record->subject ?? '(No subject)' }}</h1>

        <div aria-label="Sender">
            <span>From</span>
            @forelse ($record->from as $address)
                <span class="mb-address-chip">{{ $address['name'] !== '' ? $address['name'].' ' : '' }}{{ $address['address'] }}</span>
            @empty
                <span>Unknown sender</span>
            @endforelse
        </div>

        <div aria-label="Recipients">
            <span>To</span>
            @forelse ($record->to as $address)
                <span class="mb-address-chip">{{ $address['name'] !== '' ? $address['name'].' ' : '' }}{{ $address['address'] }}</span>
            @empty
                <span>None</span>
            @endforelse
        </div>

        @if ($record->cc !== [])
            <div aria-label="Cc recipients">
                <span>Cc</span>
                @foreach ($record->cc as $address)
                    <span class="mb-address-chip">{{ $address['name'] !== '' ? $address['name'].' ' : '' }}{{ $address['address'] }}</span>
                @endforeach
            </div>
        @endif

        @if ($record->bcc !== [])
            <div aria-label="Bcc recipients">
                <span>Bcc</span>
                @foreach ($record->bcc as $address)
                    <span class="mb-address-chip">{{ $address['name'] !== '' ? $address['name'].' ' : '' }}{{ $address['address'] }}</span>
                @endforeach
            </div>
        @endif

        <dl>
            <div><dt>Captured</dt><dd><time datetime="{{ $record->capturedAt }}">{{ \Illuminate\Support\Carbon::parse($record->capturedAt)->format('d M Y H:i:s') }}</time></dd></div>
            <div><dt>Size</dt><dd>{{ number_format($record->rawBytes) }} bytes</dd></div>
            <div><dt>Mailer</dt><dd>{{ $record->mailer ?? 'Unknown' }}</dd></div>
            <div><dt>Namespace</dt><dd>{{ $record->namespace ?? 'None' }}</dd></div>
            <div><dt>Message-ID</dt><dd>{{ $record->messageId ?? 'None' }}</dd></div>
        </dl>
    </header>

    <div class="mb-toolbar" aria-label="Message actions">
        <div role="group" aria-label="Preview viewport">
            <button type="button" data-viewport="375">Phone 375</button>
            <button type="button" data-viewport="768">Tablet 768</button>
            <button type="button" data-viewport="full">Desktop 100%</button>
        </div>

        <form method="POST" action="{{ $detail->urls['read'] }}" data-action="read">
            @csrf
            <input type="hidden" name="read" value="0">
            <button type="submit">Mark unread</button>
        </form>

        <a href="{{ $detail->urls['download'] }}">Download .eml</a>

        <form method="POST" action="{{ $detail->urls['destroy'] }}" data-action="delete">
            @csrf
            @method('DELETE')
            <button type="submit">Delete</button>
        </form>
    </div>

    <div role="tablist" aria-label="Message details">
        <button type="button" role="tab" data-tab="html" aria-selected="true" aria-controls="tab-html">HTML</button>
        <button type="button" role="tab" data-tab="text" aria-selected="false" aria-controls="tab-text">Text</button>
        <button type="button" role="tab" data-tab="headers" aria-selected="false" aria-controls="tab-headers">Headers</button>
        <button type="button" role="tab" data-tab="envelope" aria-selected="false" aria-controls="tab-envelope">Envelope</button>
        <button type="button" role="tab" data-tab="mime" aria-selected="false" aria-controls="tab-mime">MIME</button>
        <button type="button" role="tab" data-tab="raw" aria-selected="false" aria-controls="tab-raw">Raw</button>
        <button type="button" role="tab" data-tab="attachments" aria-selected="false" aria-controls="tab-attachments">Attachments</button>
        <button type="button" role="tab" data-tab="links" aria-selected="false" aria-controls="tab-links">Links</button>
        <button type="button" role="tab" data-tab="diagnostics" aria-selected="false" aria-controls="tab-diagnostics">Diagnostics</button>
    </div>

    <section role="tabpanel" id="tab-html" aria-label="HTML preview">
        @if ($detail->hasHtml)
            <iframe sandbox src="{{ $detail->urls['previewHtml'] }}" title="HTML preview" class="mb-preview" data-preview></iframe>
        @else
            <p>No HTML body.</p>
        @endif
    </section>

    <section role="tabpanel" id="tab-text" aria-label="Text preview" hidden>
        @if ($detail->text !== null)
            <iframe sandbox src="{{ $detail->urls['previewText'] }}" title="Text preview" class="mb-preview" data-preview></iframe>
        @else
            <p>No text body.</p>
        @endif
    </section>

    <section role="tabpanel" id="tab-headers" aria-label="Headers" hidden>
        <table>
            <thead><tr><th scope="col">Header</th><th scope="col">Value</th></tr></thead>
            <tbody>
                @foreach ($record->rawHeaders as [$name, $value])
                    <tr><th scope="row">{{ $name }}</th><td>{{ $value }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section role="tabpanel" id="tab-envelope" aria-label="Envelope" hidden>
        <dl>
            <dt>Envelope sender</dt><dd>{{ $record->envelopeSender ?? 'None' }}</dd>
            <dt>Envelope recipients</dt><dd>{{ implode(', ', $record->envelopeRecipients) }}</dd>
            <dt>Original To</dt><dd>{{ implode(', ', array_column($record->to, 'address')) }}</dd>
            <dt>Original Cc</dt><dd>{{ implode(', ', array_column($record->cc, 'address')) }}</dd>
            <dt>Original Bcc</dt><dd>{{ implode(', ', array_column($record->bcc, 'address')) }}</dd>
            <dt>Reply-To</dt><dd>{{ implode(', ', array_column($record->replyTo, 'address')) }}</dd>
        </dl>
    </section>

    <section role="tabpanel" id="tab-mime" aria-label="MIME structure" hidden>
        <ol class="mb-mime-tree">
            @foreach ($detail->mimeTree as $node)
                <li data-depth="{{ $node['depth'] }}">{{ $node['label'] }}</li>
            @endforeach
        </ol>
    </section>

    <section role="tabpanel" id="tab-raw" aria-label="Raw message" hidden>
        <pre>{{ \Illuminate\Support\Str::limit($raw, 256 * 1024) }}</pre>
        <a href="{{ $detail->urls['raw'] }}">View full raw message</a>
    </section>

    <section role="tabpanel" id="tab-attachments" aria-label="Attachments" hidden>
        <ol>
            @forelse ($detail->attachments as $attachment)
                <li>
                    @if ($attachment['inlineable'])
                        <img src="{{ $attachment['url'] }}" alt="">
                    @endif
                    <span>{{ $attachment['filename'] }}</span>
                    <span>{{ $attachment['part']->mediaType }}/{{ $attachment['part']->mediaSubtype }}</span>
                    <span>{{ number_format($attachment['size']) }} bytes</span>
                    <a href="{{ $attachment['url'] }}">Download</a>
                </li>
            @empty
                <li>No attachments.</li>
            @endforelse
        </ol>
    </section>

    <section role="tabpanel" id="tab-links" aria-label="Links" hidden>
        <table>
            <thead><tr><th scope="col">Text</th><th scope="col">URL</th><th scope="col">Action</th></tr></thead>
            <tbody>
                @forelse ($detail->links as $link)
                    <tr>
                        <td>{{ $link['text'] }}</td>
                        <td>{{ $link['url'] }}</td>
                        <td>
                            @if ($link['openable'])
                                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">Open</a>
                            @else
                                Not openable
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3">No links.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section role="tabpanel" id="tab-diagnostics" aria-label="Diagnostics" hidden>
        <p>Rules version {{ $detail->diagnostics['rules_version'] }}</p>
        <ol>
            @forelse ($detail->diagnostics['results'] as $result)
                <li data-severity="{{ $result['severity'] }}">
                    <strong>{{ $result['severity'] }}</strong>
                    <code>{{ $result['rule'] }}</code>
                    <span>{{ $result['message'] }}</span>
                </li>
            @empty
                <li>No diagnostics.</li>
            @endforelse
        </ol>
    </section>
</article>
