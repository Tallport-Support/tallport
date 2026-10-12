@php(\FruitUI\Support\ComponentContract::validate('drilldown', $attributes))
{{-- Levels side by side, each opened from a row of the level before. Where they don't all fit, the
     deepest show and the first leads back; a phone shows one. Inside a Workspace it fills the frame. --}}
<div {{ $attributes->class(['f-drilldown']) }}>
    {{ $slot }}
</div>
