@props(['wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('combobox', $attributes, $fruitField))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge(['data-fruit-no-matches' => __('No matches')])->class(['f-combobox']) }} x-data="fruitCombobox">
    <select data-fruit-control {{ $attributes->class(['f-input']) }}>{{ $slot }}</select>
    <div data-fruit-ui wire:ignore></div>
</div>
