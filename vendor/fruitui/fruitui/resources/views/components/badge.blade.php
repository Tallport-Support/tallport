@props(['tone' => 'neutral', 'variant' => 'filled'])
@php(\FruitUI\Support\ComponentContract::validate('badge', $attributes, ['tone' => $tone, 'variant' => $variant]))
<span {{ $attributes->class(['f-badge', 'f-badge--'.$tone => $tone !== 'neutral', 'f-badge--outline' => $variant === 'outline']) }}>@isset($dot)<span class="f-badge__dot" aria-hidden="true"></span>@endisset{{ $slot }}</span>
