@php(\FruitUI\Support\ComponentContract::validate('description-list', $attributes))
<dl {{ $attributes->class(['f-description-list']) }}>
    {{ $slot }}
</dl>
