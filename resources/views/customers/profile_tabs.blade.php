{{-- A customer's pages. Modules add theirs with customers.profile_tabs.append. --}}
<x-page-nav :label="__('Customer Profile')">
    <a href="{{ route('customers.update', ['id'=>$customer->id]) }}" @if (Route::currentRouteName() == 'customers.update') aria-current="page" @endif>{{ __('Edit Profile') }}</a>
    <a href="{{ route('customers.conversations', ['id'=>$customer->id]) }}" @if (Route::currentRouteName() == 'customers.conversations') aria-current="page" @endif>{{ __('Conversations') }}</a>
    @php
        $nostr_keys_count = App\Nostr\CustomerKey::where('customer_id', $customer->id)->count();
    @endphp
    @if ($nostr_keys_count || App\Nostr\NostrMailbox::anyActive())
        @include('nostr/partials/profile_tab', ['customer_id' => $customer->id, 'count' => $nostr_keys_count])
    @endif
    @if (!empty($extra_tab))
        <a href="#" aria-current="page">{{ $extra_tab }}</a>
    @endif
    @action('customers.profile_tabs.append')
</x-page-nav>
