@props(['checked' => false, 'shortcut' => null])
@php($clientBound = \FruitUI\Support\ComponentContract::menuChoice('menu-radio', $checked, $attributes))
<button type="button" role="menuitemradio" @unless($clientBound) aria-checked="{{ $checked ? 'true' : 'false' }}" @endunless {{ $attributes->except(['type', 'role'])->class(['f-menu-item']) }}>{{ $slot }}@if($shortcut !== null)<kbd class="f-menu-item__shortcut" aria-hidden="true">{{ $shortcut }}</kbd>@endif</button>
