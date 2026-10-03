@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('textarea', $attributes, $fruitField))
<textarea {{ $attributes->class(['f-input']) }}>{{ $slot }}</textarea>
