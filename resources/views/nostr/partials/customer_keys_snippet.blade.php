{{-- The customer's Nostr devices, by name (keys' addresses aren't for people to read). --}}
@php $keys = collect($keys)->filter(fn ($key) => $key->label); @endphp
@if (count($keys))
    <div class="customer-section">
        <ul class="customer-contacts">
            @foreach ($keys as $key)
                <li>
                    <x-icon.zap class="f-icon nostr-key-icon" aria-hidden="true" />
                    <a class="nostr-device-label" data-nostr-pubkey="{{ $key->pubkey }}" href="{{ route('customers.nostr', ['id' => $key->customer_id]) }}">{{ $key->label }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
