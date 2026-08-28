<ol class="mb-list" role="list">
    @forelse ($messages as $m)
        @php($sender = $m->from[0] ?? ['name' => '', 'address' => 'Unknown sender'])
        <li>
            <a href="{{ route('mailbox.message', ['id' => $m->id]) }}"
               data-message="{{ $m->id }}"
               class="mb-row @if (! $m->isRead()) is-unread @endif @if ($selectedId === $m->id) is-selected @endif"
               aria-current="{{ $selectedId === $m->id ? 'true' : 'false' }}">
                <span class="mb-row-from">
                    @if ($sender['name'] !== '')
                        <span>{{ $sender['name'] }}</span>
                    @endif
                    <span>{{ $sender['address'] }}</span>
                </span>
                <strong>{{ $m->subject ?? '(No subject)' }}</strong>
                <span>{{ $m->previewText ?? 'No preview available' }}</span>
                <time datetime="{{ $m->capturedAt }}">{{ \Illuminate\Support\Carbon::parse($m->capturedAt)->format('d M Y H:i') }}</time>
                @if ($m->attachmentCount > 0)
                    <span aria-label="Has attachments">Attachment</span>
                @endif
                @if ($m->parseStatus !== 'ok')
                    <span class="mb-parse-badge">{{ $m->parseStatus }}</span>
                @endif
            </a>
        </li>
    @empty
        <li>No messages match these filters.</li>
    @endforelse
</ol>
