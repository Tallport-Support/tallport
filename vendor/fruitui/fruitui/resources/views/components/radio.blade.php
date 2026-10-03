@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('radio', $attributes, $fruitField))
<label class="f-check">
    <input type="radio" role="radio" {{ $attributes }}>
    <span>{{ $slot }}</span>
</label>
