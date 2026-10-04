@props(['legend', 'legendHidden' => false])
@php(\FruitUI\Support\ComponentContract::segmented($legendHidden, $attributes))
{{-- legend-hidden keeps the group's name for assistive technology only, as in a toolbar. --}}
<fieldset {{ $attributes->class(['f-fieldset']) }}>
    <legend @if ($legendHidden) class="f-sr-only" @endif>{{ $legend }}</legend>
    <div class="f-segmented">{{ $slot }}</div>
</fieldset>
