@props(['frame' => 'card'])
@php(\FruitUI\Support\ComponentContract::validate('workspace', $attributes, ['frame' => $frame]))
{{-- card: a framed window within a page. fill: the whole app window, the viewport's full height. --}}
<section {{ $attributes->class(['f-workspace', 'f-workspace--fill' => $frame === 'fill']) }}>
    {{ $slot }}
</section>
