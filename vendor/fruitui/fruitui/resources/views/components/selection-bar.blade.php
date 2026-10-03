@props(['count' => 0])
@php(\FruitUI\Support\ComponentContract::selectionBar($count, $attributes))
@if((int) $count > 0)
    <div role="region" {{ $attributes->except('role')->class(['f-selection-bar'])->merge(['aria-label' => __('Selection')]) }}>
        <span class="f-selection-bar__count" role="status">{{ trans_choice(':count selected', (int) $count, ['count' => (int) $count]) }}</span>
        {{ $slot }}
    </div>
@endif
