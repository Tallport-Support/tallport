@props(['label'])
@php(\FruitUI\Support\ComponentContract::validate('command-group', $attributes))
<div role="group" aria-label="{{ $label }}" {{ $attributes->except(['role', 'aria-label'])->class(['f-command-palette__group']) }}>
    <div class="f-command-palette__group-label" aria-hidden="true">{{ $label }}</div>
    {{ $slot }}
</div>
