{{-- Where a message came from: the device's name (a key's address isn't for people to read). --}}
@if ($sender && $sender->label)
    @php
        // Modules may add where the message was sent from (app, version).
        $source = (string) \Eventy::filter('nostr.message_source', '', $thread);
    @endphp
    <div class="nostr-thread-from">
        <strong>{{ __('From') }}:</strong>
        <span class="nostr-device-label" data-nostr-pubkey="{{ $sender->pubkey }}">{{ $sender->label }}</span>
        @if ($source !== '')
            <span class="nostr-message-source"> · {{ $source }}</span>
        @endif
    </div>
@endif
