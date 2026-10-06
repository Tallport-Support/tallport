@props(['current' => false, 'mark' => null])
@php(\FruitUI\Support\ComponentContract::sidebarItem($current, $attributes))
@php($mark = \FruitUI\Support\ComponentContract::mark($mark))
<a {{ $attributes->class(['f-sidebar__item'])->merge(['aria-current' => $current ? 'page' : null, 'data-fruit-mark' => $mark]) }}>
    @isset($icon){{ $icon }}@endisset
    <span class="f-sidebar__identity">{{ $slot }}</span>
    @isset($badge)<span {{ $badge->attributes->class(['f-badge']) }}>{{ $badge }}</span>@endisset
</a>
