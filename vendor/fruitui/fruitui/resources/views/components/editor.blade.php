@props(['wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('editor', $attributes, $fruitField))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge([
    'data-fruit-link-label' => __('Link address'),
    'data-fruit-image-label' => __('Image address'),
    'data-fruit-apply-label' => __('Apply'),
    'data-fruit-insert-label' => __('Insert'),
    'data-fruit-remove-link-label' => __('Remove link'),
])->class(['f-editor']) }} x-data="fruitEditor">
    <div class="f-editor__toolbar" wire:ignore hidden aria-label="{{ __('Text formatting') }}">
        @isset($toolbar)
            {{ $toolbar }}
        @else
        @foreach(['bold' => __('Bold'), 'italic' => __('Italic'), 'bulletList' => __('Bullets'), 'orderedList' => __('Numbered list'), 'blockquote' => __('Quote'), 'link' => __('Link'), 'image' => __('Image'), 'clear' => __('Remove formatting'), 'undo' => __('Undo'), 'redo' => __('Redo')] as $command => $label)
            <button class="f-button f-button--ghost" type="button" data-fruit-command="{{ $command }}">{{ $label }}</button>
        @endforeach
        @endisset
        @isset($extras)
            {{-- Application buttons after the defaults: saved replies, variables, attachments. --}}
            <span class="f-editor__separator" role="separator" aria-orientation="vertical"></span>
            {{ $extras }}
        @endisset
    </div>
    <textarea data-fruit-control {{ $attributes->class(['f-input']) }}>{{ $slot }}</textarea>
    <div class="f-editor__surface" wire:ignore hidden></div>
</div>
