@props(['legend'])
@php(\FruitUI\Support\ComponentContract::validate('segmented', $attributes))
<fieldset {{ $attributes->class(['f-fieldset']) }}>
    <legend>{{ $legend }}</legend>
    <div class="f-segmented">{{ $slot }}</div>
</fieldset>
