@props(['type' => 'text'])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('input', $attributes, $fruitField, ['type' => $type]))
<input type="{{ $type }}" {{ $attributes->class(['f-input']) }}>
