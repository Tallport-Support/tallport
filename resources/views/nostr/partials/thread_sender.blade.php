@if ($sender)
    @php
        // Modules may add where the message was sent from (app, version).
        $source = (string) \Eventy::filter('nostr.message_source', '', $thread);
    @endphp
    <div class="nostr-thread-from">
        <strong>{{ __('From') }}:</strong>
        <span class="nostr-device-label" data-nostr-pubkey="{{ $sender->pubkey }}" title="{{ \App\Nostr\Keys::npub($sender->pubkey) }}">{{ $sender->label ?: \App\Nostr\Keys::shortNpub($sender->pubkey) }}</span>
        @if ($source !== '')
            <span class="nostr-message-source"> · {{ $source }}</span>
        @endif
    </div>
@endif
