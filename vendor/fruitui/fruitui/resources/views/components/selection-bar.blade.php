@props(['count' => 0])
@php(\FruitUI\Support\ComponentContract::selectionBar($count, $attributes))
{{-- Always rendered, hidden at zero. Scripts set data-count and the bar updates its own text and visibility. --}}
<div role="region" {{ $attributes->except(['role', 'hidden', 'data-count'])->class(['f-selection-bar'])->merge(['aria-label' => __('Selection')]) }} data-count="{{ (int) $count }}" data-fruit-template="{{ __(':count selected') }}" x-data="fruitSelectionBar" @if ((int) $count === 0) hidden @endif>
    <span class="f-selection-bar__count" role="status">{{ trans_choice(':count selected', (int) $count, ['count' => (int) $count]) }}</span>
    {{ $slot }}
</div>
