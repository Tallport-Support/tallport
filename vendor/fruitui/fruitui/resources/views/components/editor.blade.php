@props(['wrapper' => [], 'paste' => 'rich', 'formats' => null, 'layout' => 'stacked', 'enter' => 'newline'])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('editor', $attributes, $fruitField, ['paste' => $paste, 'layout' => $layout, 'enter' => $enter]))
@php($formats = \FruitUI\Support\ComponentContract::editorFormats($formats))
@php($inline = $layout === 'inline')
@php($toolbarId = ($attributes->get('id') ?? 'fruit-editor-'.\Illuminate\Support\Str::random(8)).'-formatting')
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge([
    'data-fruit-link-label' => __('Link Address'),
    'data-fruit-image-label' => __('Image Address'),
    'data-fruit-apply-label' => __('Apply'),
    'data-fruit-insert-label' => __('Insert'),
    'data-fruit-remove-link-label' => __('Remove Link'),
    'data-fruit-paste' => $paste,
    'data-fruit-enter' => $enter,
] + ($formats === null ? [] : ['data-fruit-formats' => implode(' ', $formats)]))->class(['f-editor', 'f-editor--inline' => $inline]) }} x-data="fruitEditor">
    <div class="f-editor__toolbar" id="{{ $toolbarId }}" wire:ignore hidden aria-label="{{ __('Text formatting') }}">
        @isset($toolbar)
            {{ $toolbar }}
        @else
        {{-- Compact icon buttons in groups (Lucide icons); each name is its accessible label and tooltip. --}}
        @php($groupShown = false)
        @foreach ([
            ['bold' => [__('Bold'), '<path d="M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8"/>'], 'italic' => [__('Italic'), '<line x1="19" x2="10" y1="4" y2="4"/><line x1="14" x2="5" y1="20" y2="20"/><line x1="15" x2="9" y1="4" y2="20"/>']],
            ['bulletList' => [__('Bullets'), '<path d="M3 5h.01"/><path d="M3 12h.01"/><path d="M3 19h.01"/><path d="M8 5h13"/><path d="M8 12h13"/><path d="M8 19h13"/>'], 'orderedList' => [__('Numbered List'), '<path d="M11 5h10"/><path d="M11 12h10"/><path d="M11 19h10"/><path d="M4 4h1v5"/><path d="M4 9h2"/><path d="M6.5 20H3.4c0-1 2.6-1.925 2.6-3.5a1.5 1.5 0 0 0-2.6-1.02"/>'], 'blockquote' => [__('Quote'), '<path d="M17 5H3"/><path d="M21 12H8"/><path d="M21 19H8"/><path d="M3 12v7"/>']],
            ['link' => [__('Link'), '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'], 'image' => [__('Image'), '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>'], 'clear' => [__('Remove Formatting'), '<path d="M4 7V4h16v3"/><path d="M5 20h6"/><path d="M13 4 8 20"/><path d="m15 15 5 5"/><path d="m20 15-5 5"/>']],
            ['undo' => [__('Undo'), '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/>'], 'redo' => [__('Redo'), '<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5A5.5 5.5 0 0 0 9.5 20H13"/>']],
        ] as $commands)
            {{-- Only the allowed formats; Remove Formatting comes with them, undo and redo always. --}}
            @php($commands = array_filter($commands, fn ($command) => $formats === null || in_array($command, $formats, true) || in_array($command, ['undo', 'redo'], true) || ($command === 'clear' && $formats !== []), ARRAY_FILTER_USE_KEY))
            @continue($commands === [])
            @if ($groupShown)<span class="f-editor__separator" role="separator" aria-orientation="vertical"></span>@endif
            @php($groupShown = true)
            @foreach ($commands as $command => [$label, $icon])
                <button class="f-button f-button--ghost f-button--icon" type="button" data-fruit-command="{{ $command }}" aria-label="{{ $label }}" title="{{ $label }}"><svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true">{!! $icon !!}</svg></button>
            @endforeach
        @endforeach
        @endisset
        @if (isset($extras) && !$inline)
            {{-- Application buttons after the defaults: saved replies, variables, attachments. --}}
            <span class="f-editor__separator" role="separator" aria-orientation="vertical"></span>
            {{ $extras }}
        @endif
    </div>
    <textarea data-fruit-control {{ $attributes->class(['f-input']) }} @if($inline) rows="1" @endif>{{ $slot }}</textarea>
    <div class="f-editor__surface" wire:ignore hidden></div>
    @if ($inline)
        {{-- A chat's message field: the formatting bar shows on demand above the text, and the
             application's buttons (Attach, saved replies, a touch-screen Send) sit at the end. --}}
        <div class="f-editor__actions">
            @if ($formats !== [])
                <button class="f-button f-button--ghost f-button--icon" type="button" data-fruit-formatting aria-expanded="false" aria-controls="{{ $toolbarId }}" aria-label="{{ __('Formatting') }}" title="{{ __('Formatting') }}" hidden wire:ignore><svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 16 2.536-7.328a1.02 1.02 1 0 1 1.928 0L22 16"/><path d="M15.697 14h5.606"/><path d="m2 16 4.039-9.69a.5.5 0 0 1 .923 0L11 16"/><path d="M3.304 13h6.392"/></svg></button>
            @endif
            {{ $extras ?? '' }}
        </div>
    @endif
</div>
