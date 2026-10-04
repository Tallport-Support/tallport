@props(['label' => __('Generated')])
@php(\FruitUI\Support\ComponentContract::generated($label, $attributes))
{{-- Generated text that stands on its own in a thread, such as a summary: a sparkles icon, the text
     beside it, and the label for assistive technology. --}}
<div {{ $attributes->class(['f-generated']) }}>
    <svg class="f-icon f-generated__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 3.5 11 8l4.5 1.5L11 11l-1.5 4.5L8 11 3.5 9.5 8 8zM17.5 13.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8zM17 3.5l.5 1.5 1.5.5-1.5.5-.5 1.5-.5-1.5-1.5-.5 1.5-.5z" /></svg>
    <span class="f-sr-only">{{ $label }}</span>
    <div class="f-generated__text">{{ $slot }}</div>
</div>
