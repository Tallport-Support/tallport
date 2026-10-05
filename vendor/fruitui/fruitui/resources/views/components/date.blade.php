@props(['type' => 'date', 'wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('date', $attributes, $fruitField, ['type' => $type]))
@if (in_array($type, ['date', 'datetime-local'], true))
{{-- The native input keeps the value and typing; the calendar replaces the browser's picker popup. --}}
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge([
    'data-fruit-label' => __('Choose Date'),
    'data-fruit-previous-label' => __('Previous Month'),
    'data-fruit-next-label' => __('Next Month'),
])->class(['f-date-picker']) }} x-data="fruitDatePicker">
    <input type="{{ $type }}" aria-haspopup="dialog" {{ $attributes->class(['f-input']) }}>
    <div data-fruit-ui wire:ignore></div>
</div>
@else
<input type="{{ $type }}" {{ $attributes->class(['f-input']) }}>
@endif
