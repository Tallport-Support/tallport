@php
    $profile_menu = \Eventy::filter('customer.profile_menu', '', $customer)
@endphp
<x-fruit::menu :title="__('Settings')" class="customer-profile-menu">
    <x-slot:trigger class="f-button--ghost f-button--icon f-button--small" :aria-label="__('Settings')"><x-icon.settings class="f-icon" aria-hidden="true" /></x-slot:trigger>
    <x-fruit::menu-link :href="route('customers.merge', ['id' => $customer->id])">{{ __('Merge') }}</x-fruit::menu-link>
    {{-- Modules' items (customer.profile_menu). --}}
    <ul class="menu-module-items">{!! safe_raw_html($profile_menu) !!}</ul>
</x-fruit::menu>
