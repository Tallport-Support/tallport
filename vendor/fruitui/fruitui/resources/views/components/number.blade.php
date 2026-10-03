@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('number', $attributes, $fruitField))
<input type="number" {{ $attributes->class(['f-input']) }}>
