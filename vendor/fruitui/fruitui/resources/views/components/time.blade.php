@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('time', $attributes, $fruitField))
<input type="time" {{ $attributes->class(['f-input']) }}>
