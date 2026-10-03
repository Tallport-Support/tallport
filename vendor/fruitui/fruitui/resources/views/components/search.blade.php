@props(['label' => null, 'wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::search($label, $attributes, $fruitField))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->class(['f-search']) }}>
    <svg class="f-icon" viewBox="0 0 16 16" aria-hidden="true"><circle cx="7" cy="7" r="4.75" /><path d="m10.5 10.5 3.5 3.5" /></svg>
    <input type="search" @if($label !== null) aria-label="{{ $label }}" @endif {{ $attributes->class(['f-input']) }}>
</div>
