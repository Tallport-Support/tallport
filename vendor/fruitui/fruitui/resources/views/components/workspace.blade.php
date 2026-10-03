@php(\FruitUI\Support\ComponentContract::validate('workspace', $attributes))
<section {{ $attributes->class(['f-workspace']) }}>
    {{ $slot }}
</section>
