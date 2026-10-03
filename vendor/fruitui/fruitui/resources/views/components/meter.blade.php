@php(\FruitUI\Support\ComponentContract::validate('meter', $attributes))
<meter {{ $attributes->class(['f-meter']) }}>{{ $slot }}</meter>
