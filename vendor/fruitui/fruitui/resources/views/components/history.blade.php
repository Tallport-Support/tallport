@props(['label' => __('Message History')])
@php(\FruitUI\Support\ComponentContract::validate('history', $attributes))
{{-- A message history that opens at its newest message and follows new ones; put it in a column
     pane before a docked composer. The Jump to Latest button shows after the reader scrolls back. --}}
<div role="region" tabindex="0" aria-label="{{ $attributes->get('aria-label') ?? $label }}" {{ $attributes->except(['role', 'aria-label'])->class(['f-pane__scroll', 'f-history']) }} x-data="fruitHistory">
    {{ $slot }}
    <button type="button" class="f-button f-history__latest" x-show="awayFromLatest" x-cloak x-on:click="requestLatest()"><svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>{{ __('Jump to Latest') }}</button>
</div>
