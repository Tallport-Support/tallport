@props(['wrapper' => [], 'search' => 'local'])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('combobox', $attributes, $fruitField, ['search' => $search]))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge(['data-fruit-no-matches' => __('No matches'), 'data-fruit-search' => $search === 'server' ? 'server' : null])->class(['f-combobox']) }} x-data="fruitCombobox">
    <select data-fruit-control {{ $attributes->class(['f-input']) }}>{{ $slot }}</select>
    <div data-fruit-ui wire:ignore></div>
</div>
