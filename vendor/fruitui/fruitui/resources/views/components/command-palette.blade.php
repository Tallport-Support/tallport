@props(['name' => null, 'label' => __('Commands'), 'shortcut' => null, 'placeholder' => __('Search')])
@php(\FruitUI\Support\ComponentContract::commandPalette($name, $shortcut, $attributes))
<dialog {{ $attributes->class(['f-dialog', 'f-command-palette'])->merge(['aria-label' => $label]) }} data-fruit-dialog="{{ $name }}" @if($shortcut !== null) data-fruit-shortcut="{{ $shortcut }}" @endif x-data="fruitCommandPalette" wire:ignore.self>
    <div class="f-command-palette__search">
        <svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
        <input class="f-input" type="text" role="combobox" aria-expanded="true" aria-autocomplete="list" aria-controls="{{ $name }}-commands" aria-label="{{ $label }}" placeholder="{{ $placeholder }}" autocomplete="off" spellcheck="false">
    </div>
    <div class="f-command-palette__list" role="listbox" id="{{ $name }}-commands" aria-label="{{ $label }}">{{ $slot }}</div>
    <p class="f-command-palette__empty" hidden>{{ __('No results') }}</p>
</dialog>
