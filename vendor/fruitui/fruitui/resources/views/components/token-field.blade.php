@props(['wrapper' => [], 'submit' => 'text', 'search' => 'local'])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('token-field', $attributes, $fruitField, ['submit' => $submit, 'search' => $search]))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge([
    'data-fruit-placeholder' => __('Add an item'),
    'data-fruit-remove-label' => __('Remove {value}'),
    'data-fruit-removed-message' => __('Removed {value}'),
    'data-fruit-count-message' => __('{count} items'),
    'data-fruit-invalid-message' => __('Check this value before adding it.'),
    'data-fruit-length-message' => __('Use at most {count} characters.'),
    'data-fruit-submit' => $submit === 'list' ? 'list' : null,
] + (isset($options) ? [
    'data-fruit-search' => $search,
    'data-fruit-suggestions-label' => __('Suggestions'),
    'data-fruit-suggestions-count-message' => __('{count} suggestions'),
    'data-fruit-no-suggestions' => __('No suggestions'),
] : []))->class(['f-token-field']) }} x-data="fruitTokenField">
    <textarea data-fruit-control {{ $attributes->class(['f-input']) }}>{{ $slot }}</textarea>
    @isset($options)<datalist>{{ $options }}</datalist>@endisset
    <div data-fruit-ui wire:ignore></div>
</div>
