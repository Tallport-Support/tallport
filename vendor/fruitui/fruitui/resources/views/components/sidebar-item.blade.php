@props(['current' => false])
@php(\FruitUI\Support\ComponentContract::sidebarItem($current, $attributes))
<a {{ $attributes->class(['f-sidebar__item'])->merge(['aria-current' => $current ? 'page' : null]) }}>
    @isset($icon){{ $icon }}@endisset
    <span class="f-sidebar__identity">{{ $slot }}</span>
    @isset($badge)<span {{ $badge->attributes->class(['f-badge']) }}>{{ $badge }}</span>@endisset
</a>
