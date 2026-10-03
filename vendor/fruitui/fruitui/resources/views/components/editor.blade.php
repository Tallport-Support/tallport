@props(['wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('editor', $attributes, $fruitField))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->class(['f-editor']) }} x-data="fruitEditor">
    <div class="f-editor__toolbar" wire:ignore hidden aria-label="{{ __('Text formatting') }}">
        @isset($toolbar)
            {{ $toolbar }}
        @else
        @foreach(['bold' => __('Bold'), 'italic' => __('Italic'), 'bulletList' => __('Bullets'), 'orderedList' => __('Numbered list'), 'blockquote' => __('Quote'), 'undo' => __('Undo'), 'redo' => __('Redo')] as $command => $label)
            <button class="f-button f-button--ghost" type="button" data-fruit-command="{{ $command }}">{{ $label }}</button>
        @endforeach
        @endisset
    </div>
    <textarea data-fruit-control {{ $attributes->class(['f-input']) }}>{{ $slot }}</textarea>
    <div class="f-editor__surface" wire:ignore hidden></div>
</div>
