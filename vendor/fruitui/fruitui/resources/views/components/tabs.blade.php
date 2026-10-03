@php(\FruitUI\Support\ComponentContract::validate('tabs', $attributes))
<div role="tablist" {{ $attributes->except('role')->class(['f-tabs']) }}>{{ $slot }}</div>
