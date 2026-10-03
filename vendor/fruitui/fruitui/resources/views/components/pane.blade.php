@php(\FruitUI\Support\ComponentContract::validate('pane', $attributes))
<div {{ $attributes->class(['f-pane']) }}>
    {{ $slot }}
</div>
