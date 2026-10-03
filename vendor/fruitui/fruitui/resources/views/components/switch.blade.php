@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('switch', $attributes, $fruitField))
<label class="f-switch">
    <input type="checkbox" role="switch" {{ $attributes }}>
    <span>{{ $slot }}</span>
</label>
