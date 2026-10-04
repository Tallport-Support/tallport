@props(['label' => null, 'wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::search($label, $attributes, $fruitField))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->class(['f-search']) }}>
    <svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
    <input type="search" @if($label !== null) aria-label="{{ $label }}" @endif {{ $attributes->class(['f-input']) }}>
</div>
