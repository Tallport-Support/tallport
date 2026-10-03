@php(\FruitUI\Support\ComponentContract::validate('empty-state', $attributes))
<div {{ $attributes->class(['f-empty-state']) }}>
    @isset($icon)<span {{ $icon->attributes->class(['f-empty-state__icon']) }}>{{ $icon }}</span>@endisset
    @isset($title)<div {{ $title->attributes->class(['f-empty-state__title']) }}>{{ $title }}</div>@endisset
    <div class="f-empty-state__description">{{ $slot }}</div>
    @isset($actions)<div {{ $actions->attributes->class(['f-empty-state__actions']) }}>{{ $actions }}</div>@endisset
</div>
