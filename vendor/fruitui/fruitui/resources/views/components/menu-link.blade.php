@props(['shortcut' => null])
@php(\FruitUI\Support\ComponentContract::validate('menu-link', $attributes))
<a role="menuitem" {{ $attributes->except('role')->class(['f-menu-item']) }}>{{ $slot }}@if($shortcut !== null)<kbd class="f-menu-item__shortcut" aria-hidden="true">{{ $shortcut }}</kbd>@endif</a>
