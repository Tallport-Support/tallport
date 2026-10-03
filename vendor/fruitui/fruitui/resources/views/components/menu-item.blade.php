@props(['variant' => 'default', 'shortcut' => null])
@php(\FruitUI\Support\ComponentContract::validate('menu-item', $attributes, ['variant' => $variant]))
<button type="button" role="menuitem" {{ $attributes->except(['type', 'role'])->class(['f-menu-item', 'f-menu-item--danger' => $variant === 'danger']) }}>{{ $slot }}@if($shortcut !== null)<kbd class="f-menu-item__shortcut" aria-hidden="true">{{ $shortcut }}</kbd>@endif</button>
