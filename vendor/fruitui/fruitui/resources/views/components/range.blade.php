@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('range', $attributes, $fruitField))
<input type="range" {{ $attributes->class(['f-range']) }}>
