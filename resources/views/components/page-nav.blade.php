{{-- A page's own navigation at the top of its content: Back to its parent page, a title (or a switcher),
     tabs, and actions. Back starts the title's line, so the header is as tall with it as without. --}}
@props(['label' => null])
@if (\App\Misc\Sidebar::isSettings())
    {{-- On a Settings page: Back, the title and the actions go to the bar over the pane (layouts/app);
         the tabs stay here, at the top of the content. --}}
    @if (isset($back) || isset($title) || isset($actions))
        @push('settings_bar')
            @isset($back){{ $back }}@endisset
            @isset($title)<div {{ $title->attributes->class(['settings-toolbar__title']) }}>{{ $title }}</div>@endisset
            <span class="f-toolbar__spacer"></span>
            @isset($actions)<div class="settings-toolbar__actions">{{ $actions }}</div>@endisset
        @endpush
    @endif
    @if (trim((string) $slot) !== '')
        <div {{ $attributes->class(['fruit-ui', 'page-nav', 'page-nav--tabs']) }}>
            <nav class="f-section-nav" aria-label="{{ $label ?? __('Sections') }}">{{ $slot }}</nav>
        </div>
    @endif
@else
<div {{ $attributes->class(['fruit-ui', 'page-nav']) }}>
    @if (isset($title) || isset($actions))
        <div class="page-nav__header">
            @isset($back)<div class="page-nav__back">{{ $back }}</div>@endisset
            @isset($title)<div {{ $title->attributes->class(['page-nav__title']) }}>{{ $title }}</div>@endisset
            @isset($actions)<div class="page-nav__actions">{{ $actions }}</div>@endisset
        </div>
    @endif
    @if (trim((string) $slot) !== '')
        <nav class="f-section-nav" aria-label="{{ $label ?? __('Sections') }}">{{ $slot }}</nav>
    @endif
</div>
@endif
