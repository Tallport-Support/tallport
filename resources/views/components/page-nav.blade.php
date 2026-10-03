{{-- A page's own navigation at the top of its content: a title (or a switcher), tabs, and actions. --}}
@props(['label' => null])
<div {{ $attributes->class(['fruit-ui', 'page-nav']) }}>
    @if (isset($title) || isset($actions))
        <div class="page-nav__header">
            @isset($title)<div {{ $title->attributes->class(['page-nav__title']) }}>{{ $title }}</div>@endisset
            @isset($actions)<div class="page-nav__actions">{{ $actions }}</div>@endisset
        </div>
    @endif
    @if (trim((string) $slot) !== '')
        <nav class="f-section-nav" aria-label="{{ $label ?? __('Sections') }}">{{ $slot }}</nav>
    @endif
</div>
