@props(['variant' => 'quiet'])
@php(\FruitUI\Support\ComponentContract::validate('item-row', $attributes, ['variant' => $variant]))
<button type="button" {{ $attributes->except('type')->class(['f-item-row', 'f-item-row--filled' => $variant === 'filled']) }}>
    @include('fruit::partials.item-row-body')
</button>
