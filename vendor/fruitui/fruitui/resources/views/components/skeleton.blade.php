@props(['lines' => 3])
@php(\FruitUI\Support\ComponentContract::skeleton($lines, $attributes))
<div {{ $attributes->class(['f-skeleton']) }} aria-hidden="true">
    @for ($line = 0; $line < (int) $lines; $line++)
        <div class="f-skeleton__line"></div>
    @endfor
</div>
