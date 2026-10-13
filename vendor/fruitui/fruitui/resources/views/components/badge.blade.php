@props(['tone' => 'neutral', 'variant' => 'filled'])
@php(\FruitUI\Support\ComponentContract::validate('badge', $attributes, ['tone' => $tone, 'variant' => $variant]))
@if ($variant === 'dot')
    {{-- A dot alone: an image named by the slot's text, which is also its tooltip unless one is given. --}}
    @php($name = trim(html_entity_decode(strip_tags($slot))))
    <span role="img" aria-label="{{ $name }}" {{ $attributes->class(['f-badge', 'f-badge--'.$tone => $tone !== 'neutral', 'f-badge--dot'])->merge(['title' => $name]) }}></span>
@else
<span {{ $attributes->class(['f-badge', 'f-badge--'.$tone => $tone !== 'neutral', 'f-badge--outline' => $variant === 'outline']) }}>@isset($dot)<span class="f-badge__dot" aria-hidden="true"></span>@endisset{{ $slot }}</span>
@endif
