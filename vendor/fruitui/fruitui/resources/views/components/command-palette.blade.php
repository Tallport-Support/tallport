@props(['name' => null, 'label' => __('Commands'), 'shortcut' => null, 'placeholder' => __('Search')])
@php(\FruitUI\Support\ComponentContract::commandPalette($name, $shortcut, $attributes))
<dialog {{ $attributes->class(['f-dialog', 'f-command-palette'])->merge(['aria-label' => $label]) }} data-fruit-dialog="{{ $name }}" @if($shortcut !== null) data-fruit-shortcut="{{ $shortcut }}" @endif x-data="fruitCommandPalette" wire:ignore.self>
    <div class="f-command-palette__search">
        <svg class="f-icon" viewBox="0 0 16 16" aria-hidden="true"><circle cx="7" cy="7" r="4.75" /><path d="m10.5 10.5 3.5 3.5" /></svg>
        <input class="f-input" type="text" role="combobox" aria-expanded="true" aria-autocomplete="list" aria-controls="{{ $name }}-commands" aria-label="{{ $label }}" placeholder="{{ $placeholder }}" autocomplete="off" spellcheck="false">
    </div>
    <div class="f-command-palette__list" role="listbox" id="{{ $name }}-commands" aria-label="{{ $label }}">{{ $slot }}</div>
    <p class="f-command-palette__empty" hidden>{{ __('No results') }}</p>
</dialog>
