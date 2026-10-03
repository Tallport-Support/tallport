@props(['available' => true, 'label' => null])
@php(\FruitUI\Support\ComponentContract::presence($available, $attributes))
<span {{ $attributes->class(['f-presence']) }} data-available="{{ $available ? 'true' : 'false' }}" aria-hidden="true"></span>@if($label !== null)<span class="f-sr-only">{{ $label }}</span>@endif
