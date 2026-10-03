@php(\FruitUI\Support\ComponentContract::validate('composer', $attributes))
<form {{ $attributes->class(['f-composer']) }}>
    {{ $slot }}
</form>
