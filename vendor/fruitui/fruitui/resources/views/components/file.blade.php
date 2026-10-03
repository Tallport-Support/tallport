@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('file', $attributes, $fruitField))
<input type="file" {{ $attributes->class(['f-input f-file']) }}>
