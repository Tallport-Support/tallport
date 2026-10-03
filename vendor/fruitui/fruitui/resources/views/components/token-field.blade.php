@props(['wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('token-field', $attributes, $fruitField))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge([
    'data-fruit-placeholder' => __('Add an item'),
    'data-fruit-remove-label' => __('Remove {value}'),
    'data-fruit-removed-message' => __('Removed {value}'),
    'data-fruit-count-message' => __('{count} items'),
    'data-fruit-invalid-message' => __('Check this value before adding it.'),
    'data-fruit-length-message' => __('Use at most {count} characters.'),
])->class(['f-token-field']) }} x-data="fruitTokenField">
    <textarea data-fruit-control {{ $attributes->class(['f-input']) }}>{{ $slot }}</textarea>
    <div data-fruit-ui wire:ignore></div>
</div>
