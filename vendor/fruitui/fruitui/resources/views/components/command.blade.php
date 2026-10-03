@props(['shortcut' => null])
@php(\FruitUI\Support\ComponentContract::validate('command', $attributes))
<button type="button" role="option" aria-selected="false" tabindex="-1" {{ $attributes->except(['type', 'role', 'aria-selected', 'tabindex'])->class(['f-command']) }}>@isset($icon)<span class="f-command__icon" aria-hidden="true">{{ $icon }}</span>@endisset<span class="f-command__label">{{ $slot }}</span>@if($shortcut !== null)<kbd class="f-command__shortcut" aria-hidden="true">{{ $shortcut }}</kbd>@endif</button>
