@aware(['fruitField' => null])
{{-- control-size, not size: a select's native size attribute shows that many rows as a list box. --}}
@props(['controlSize' => 'regular', 'variant' => 'default'])
@php($attributes = \FruitUI\Support\ComponentContract::control('select', $attributes, $fruitField, ['control-size' => $controlSize, 'variant' => $variant]))
{{-- A quiet select stays on one line: the customizable select shows the chosen option through this button, whose content the browser fills (so Livewire leaves it alone). --}}
<select {{ $attributes->class(['f-input', 'f-input--small' => $controlSize === 'small', 'f-input--quiet' => $variant === 'quiet']) }}>@if ($variant === 'quiet')<button type="button" wire:ignore><selectedcontent></selectedcontent></button>@endif{{ $slot }}</select>
