@php(\FruitUI\Support\ComponentContract::validate('item-list', $attributes))
<ul role="list" {{ $attributes->except('role')->class(['f-item-list']) }}>{{ $slot }}</ul>
