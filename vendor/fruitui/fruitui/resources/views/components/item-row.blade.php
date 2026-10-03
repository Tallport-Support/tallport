@props(['variant' => 'quiet'])
@php(\FruitUI\Support\ComponentContract::validate('item-row', $attributes, ['variant' => $variant]))
<button type="button" {{ $attributes->except('type')->class(['f-item-row', 'f-item-row--filled' => $variant === 'filled']) }}>
    @isset($leading)<span {{ $leading->attributes->class(['f-item-row__leading']) }}>{{ $leading }}</span>@endisset
    <span class="f-item-row__top">
        <span class="f-item-row__title">{{ $title ?? $slot }}</span>
        @isset($trailing)<span {{ $trailing->attributes->class(['f-item-row__time']) }}>{{ $trailing }}</span>@endisset
    </span>
    @isset($subtitle)<span {{ $subtitle->attributes->class(['f-item-row__subtitle']) }}>{{ $subtitle }}</span>@endisset
    @isset($preview)<span {{ $preview->attributes->class(['f-item-row__preview']) }}>{{ $preview }}</span>@endisset
    @isset($meta)<span {{ $meta->attributes->class(['f-item-row__meta']) }}>{{ $meta }}</span>@endisset
</button>
