@props(['tone' => 'neutral', 'align' => 'center'])
@php(\FruitUI\Support\ComponentContract::validate('divider', $attributes, ['tone' => $tone, 'align' => $align]))
{{-- The visible text names the separator, unless aria-label says more ("New" read as "New messages"). --}}
@php($text = trim(strip_tags($slot)))
@php($label = trim((string) $attributes->get('aria-label', $text)))
<div role="separator" @if($label !== '') aria-label="{{ $label }}" @endif {{ $attributes->except(['role', 'aria-label'])->class(['f-divider', 'f-divider--accent' => $tone === 'accent', 'f-divider--start' => $align === 'start']) }}>@if($text !== '')<span aria-hidden="true">{{ $slot }}</span>@endif</div>
