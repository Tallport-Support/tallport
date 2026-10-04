@props(['wrapper' => [], 'paste' => 'rich'])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('editor', $attributes, $fruitField, ['paste' => $paste]))
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge([
    'data-fruit-link-label' => __('Link address'),
    'data-fruit-image-label' => __('Image address'),
    'data-fruit-apply-label' => __('Apply'),
    'data-fruit-insert-label' => __('Insert'),
    'data-fruit-remove-link-label' => __('Remove link'),
    'data-fruit-paste' => $paste,
])->class(['f-editor']) }} x-data="fruitEditor">
    <div class="f-editor__toolbar" wire:ignore hidden aria-label="{{ __('Text formatting') }}">
        @isset($toolbar)
            {{ $toolbar }}
        @else
        {{-- Compact icon buttons in groups; each name is its accessible label and tooltip. --}}
        @foreach ([
            ['bold' => [__('Bold'), 'M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z'], 'italic' => [__('Italic'), 'M19 5h-8M13 19H5M15 5 9 19']],
            ['bulletList' => [__('Bullets'), 'M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01'], 'orderedList' => [__('Numbered list'), 'M10 6h10M10 12h10M10 18h10M4 4.5l1-.5v4M3.5 14.5c.4-.6 2-.8 2 .3 0 .8-2 1.6-2 2.7h2'], 'blockquote' => [__('Quote'), 'M10 7c-2.5 1-4 3-4 6v4h4v-4H7M18 7c-2.5 1-4 3-4 6v4h4v-4h-3']],
            ['link' => [__('Link'), 'M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1'], 'image' => [__('Image'), 'M4 5h16v14H4zM4 16l5-5 4 4 2-2 5 5M15.5 9h.01'], 'clear' => [__('Remove formatting'), 'M6 5h12M12 5l-3 14M4 4l16 16']],
            ['undo' => [__('Undo'), 'M9 7 4 12l5 5M4 12h11a5 5 0 0 1 0 10h-2'], 'redo' => [__('Redo'), 'm15 7 5 5-5 5M20 12H9a5 5 0 0 0 0 10h2']],
        ] as $group => $commands)
            @if ($group > 0)<span class="f-editor__separator" role="separator" aria-orientation="vertical"></span>@endif
            @foreach ($commands as $command => [$label, $icon])
                <button class="f-button f-button--ghost f-button--icon" type="button" data-fruit-command="{{ $command }}" aria-label="{{ $label }}" title="{{ $label }}"><svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}" /></svg></button>
            @endforeach
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
