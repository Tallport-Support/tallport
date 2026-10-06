{{-- The customer's Nostr devices, by name (keys' addresses aren't for people to read): rows of the
     customer's details (customers/profile_snippet). --}}
@foreach ($keys as $key)
    <li><x-icon.zap class="f-icon" aria-hidden="true" /><span class="f-sr-only">Nostr: </span><a class="nostr-device-label" data-nostr-pubkey="{{ $key->pubkey }}" href="{{ route('customers.nostr', ['id' => $key->customer_id]) }}">{{ $key->label }}</a></li>
@endforeach
