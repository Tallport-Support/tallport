@props(['label' => null, 'src' => null])
@php(\FruitUI\Support\ComponentContract::validate('avatar', $attributes))
@php($named = is_string($label) && trim($label) !== '')
@if($src !== null)
<span {{ $attributes->class(['f-avatar']) }} @unless($named) aria-hidden="true" @endunless><img src="{{ $src }}" alt="{{ $named ? $label : '' }}"></span>
@else
<span {{ $attributes->class(['f-avatar']) }} @if($named) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>{{ $slot }}</span>
@endif
