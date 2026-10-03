@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('select', $attributes, $fruitField))
<select {{ $attributes->class(['f-input']) }}>{{ $slot }}</select>
