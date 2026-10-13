@props(['tone' => 'neutral', 'variant' => 'filled'])
@php(\FruitUI\Support\ComponentContract::validate('badge', $attributes, ['tone' => $tone, 'variant' => $variant]))
{{-- A dot alone (variant dot) is named by its text, which also shows as its tooltip. --}}
<span {{ $attributes->class(['f-badge', 'f-badge--'.$tone => $tone !== 'neutral', 'f-badge--outline' => $variant === 'outline', 'f-badge--dot' => $variant === 'dot'])->merge($variant === 'dot' ? ['title' => trim(html_entity_decode(strip_tags($slot)))] : []) }}>@isset($dot)<span class="f-badge__dot" aria-hidden="true"></span>@endisset{{ $slot }}</span>
