@if (count($keys))
    <div class="customer-section">
        <ul class="customer-contacts">
            @foreach ($keys as $key)
                <li title="{{ $key->getNpub() }}">
                    <x-heroicon-o-bolt class="f-icon nostr-key-icon" aria-hidden="true" />
                    <a class="nostr-device-label" data-nostr-pubkey="{{ $key->pubkey }}" href="{{ route('customers.nostr', ['id' => $key->customer_id]) }}">{{ $key->label ?: $key->getShortNpub() }}</a>
                    @if ($key->label)<small class="f-muted">{{ $key->getShortNpub() }}</small>@endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
