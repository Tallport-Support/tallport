@php
    $profile_menu = \Eventy::filter('customer.profile_menu', '', $customer)
@endphp
<div class="dropdown customer-profile-menu">
    <button type="button" class="f-button f-button--ghost f-button--icon f-button--small dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('Settings') }}"><x-heroicon-o-cog-6-tooth class="f-icon" aria-hidden="true" /></button>
    <ul class="dropdown-menu dropdown-menu-right">
        <li>
			<a href="{{ route('customers.merge', ['id' => $customer->id]) }}">{{ __('Merge') }}</a>
        </li>
        {!! safe_raw_html($profile_menu) !!}
    </ul>
</div>
