@props(['name' => 'accent', 'value' => 'blue', 'label' => __('Accent Color')])
@php(\FruitUI\Support\ComponentContract::accentPicker($value, $attributes))
@php($names = ['blue' => __('Blue'), 'purple' => __('Purple'), 'pink' => __('Pink'), 'red' => __('Red'), 'orange' => __('Orange'), 'yellow' => __('Yellow'), 'green' => __('Green'), 'graphite' => __('Graphite')])
@php($binding = $attributes->filter(fn ($value, $key) => str_starts_with($key, 'wire:model') || str_starts_with($key, 'x-model') || in_array($key, ['form', 'disabled', 'required'], true)))
{{-- The accent colors as native radios: arrow keys move between them; each circle shows its accent. --}}
<div role="radiogroup" {{ $attributes->except(array_keys($binding->getAttributes()))->merge(['aria-label' => $label])->class(['f-accent-picker']) }}>
    @foreach ($names as $accent => $accentName)
        <label class="f-accent-picker__option" data-fruit-accent="{{ $accent }}" title="{{ $accentName }}">
            <input type="radio" name="{{ $name }}" value="{{ $accent }}" @checked($value === $accent) {{ $binding }}>
            <span class="f-sr-only">{{ $accentName }}</span>
        </label>
    @endforeach
</div>
