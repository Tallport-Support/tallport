@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('checkbox', $attributes, $fruitField))
<label class="f-check">
    <input type="checkbox" role="checkbox" {{ $attributes }}>
    <span>{{ $slot }}</span>
</label>
