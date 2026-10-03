@php(\FruitUI\Support\ComponentContract::validate('table', $attributes))
<table {{ $attributes->class(['f-table']) }}>
    {{ $slot }}
</table>
