@aware(['fruitField' => null])
{{-- control-size, not size: a select's native size attribute shows that many rows as a list box. --}}
@props(['controlSize' => 'regular'])
@php($attributes = \FruitUI\Support\ComponentContract::control('select', $attributes, $fruitField, ['control-size' => $controlSize]))
<select {{ $attributes->class(['f-input', 'f-input--small' => $controlSize === 'small']) }}>{{ $slot }}</select>
