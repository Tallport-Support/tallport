@props(['variant' => 'quiet', 'current' => false])
@php(\FruitUI\Support\ComponentContract::validate('item-link', $attributes, ['variant' => $variant]))
{{-- A row that is a destination: a real link that opens in a new tab and works without JavaScript. --}}
<a {{ $attributes->merge(['aria-current' => $current ? 'page' : null])->class(['f-item-row', 'f-item-row--filled' => $variant === 'filled']) }}>
    @include('fruit::partials.item-row-body')
</a>
