{{-- The workspace's first toolbar: the brand. --}}
<a class="app-sidebar__brand" href="{{ route('dashboard', ['dashboard' => 1]) }}" title="{{ __('Dashboard') }}">
    @if (($sidebar_logo = \Eventy::filter('layout.header_logo', '')) !== '')
        <img src="{{ $sidebar_logo }}" alt="{{ __('Dashboard') }}" />
    @else
        {{-- img/logo-brand.svg in the brand colour. --}}
        <svg class="app-sidebar__logo" viewBox="0 0 208 208" role="img" aria-label="{{ __('Dashboard') }}"><path fill="currentColor" fill-rule="evenodd" d="M54 0H154A54 54 0 0 1 208 54V154A54 54 0 0 1 154 208H54A54 54 0 0 1 0 154V54A54 54 0 0 1 54 0ZM52 54H156C169 54 179 64 179 77V87C166 88 156 99 156 112C156 125 166 136 179 137V150C179 163 169 173 156 173H84L55 196C49 201 40 197 40 189V137C53 136 63 125 63 112C63 99 53 88 40 87V77C40 64 40 54 52 54Z"/></svg>
    @endif
</a>
