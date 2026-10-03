@php(\FruitUI\Support\ComponentContract::validate('tab', $attributes))
<button type="button" role="tab" {{ $attributes->except(['type', 'role'])->class(['f-tab']) }}>{{ $slot }}</button>
