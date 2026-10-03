@props(['tone' => 'neutral', 'align' => 'center'])
@php(\FruitUI\Support\ComponentContract::validate('divider', $attributes, ['tone' => $tone, 'align' => $align]))
@php($label = trim(strip_tags($slot)))
<div role="separator" @if($label !== '') aria-label="{{ $label }}" @endif {{ $attributes->except(['role', 'aria-label'])->class(['f-divider', 'f-divider--accent' => $tone === 'accent', 'f-divider--start' => $align === 'start']) }}>@if($label !== '')<span aria-hidden="true">{{ $slot }}</span>@endif</div>
