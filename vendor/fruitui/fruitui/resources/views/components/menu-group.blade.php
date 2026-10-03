@props(['label'])
@php(\FruitUI\Support\ComponentContract::validate('menu-group', $attributes))
<div role="group" aria-label="{{ $label }}" {{ $attributes->except(['role', 'aria-label'])->class(['f-menu__group']) }}>
    <div class="f-menu__group-label" aria-hidden="true">{{ $label }}</div>
    {{ $slot }}
</div>
