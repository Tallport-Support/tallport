@aware(['fruitField' => null])
{{-- control-size, not size: a select's native size attribute shows that many rows as a list box. --}}
@props(['controlSize' => 'regular', 'variant' => 'default'])
@php($attributes = \FruitUI\Support\ComponentContract::control('select', $attributes, $fruitField, ['control-size' => $controlSize, 'variant' => $variant]))
<select {{ $attributes->class(['f-input', 'f-input--small' => $controlSize === 'small', 'f-input--quiet' => $variant === 'quiet']) }}>{{ $slot }}</select>
