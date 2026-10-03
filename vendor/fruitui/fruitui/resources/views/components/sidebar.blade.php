@php(\FruitUI\Support\ComponentContract::validate('sidebar', $attributes))
<nav {{ $attributes->class(['f-sidebar']) }}>
    @isset($header)<div {{ $header->attributes->class(['f-sidebar__header']) }}>{{ $header }}</div>@endisset
    {{ $slot }}
    @isset($footer)<div {{ $footer->attributes->class(['f-sidebar__footer']) }}>{{ $footer }}</div>@endisset
</nav>
