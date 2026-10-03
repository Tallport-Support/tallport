@props(['trigger' => null])
@php(\FruitUI\Support\ComponentContract::autocomplete($trigger, $attributes))
<div {{ $attributes->class(['f-autocomplete'])->merge([
    'data-fruit-trigger' => $trigger,
    'data-fruit-label' => __('Suggestions'),
    'data-fruit-count-message' => __('{count} suggestions'),
    'data-fruit-no-suggestions' => __('No suggestions'),
]) }} x-data="fruitAutocomplete">
    {{ $slot }}
    @isset($options)<datalist>{{ $options }}</datalist>@endisset
    <div data-fruit-ui wire:ignore></div>
</div>
