{{-- The workspace's first toolbar: the brand. --}}
<a class="app-sidebar__brand" href="{{ route('dashboard', ['dashboard' => 1]) }}" title="{{ __('Dashboard') }}">
    @if (($sidebar_logo = \Eventy::filter('layout.header_logo', '')) !== '')
        <x-themed-image :src="$sidebar_logo" :dark="\Eventy::filter('layout.header_logo_dark', '')" :alt="__('Dashboard')" />
    @else
        <x-logo class="app-sidebar__logo" role="img" :aria-label="__('Dashboard')" />
    @endif
</a>
