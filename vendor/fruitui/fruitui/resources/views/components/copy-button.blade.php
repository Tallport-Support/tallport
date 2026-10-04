@props(['value' => '', 'variant' => 'default', 'size' => 'regular'])
@php(\FruitUI\Support\ComponentContract::validate('copy-button', $attributes, ['variant' => $variant, 'size' => $size]))
@php($iconOnly = $slot->isEmpty())
{{-- The button copies its data-fruit-copy text; an icon-only button is labelled "Copy" unless given aria-label. --}}
<span class="f-copy" x-data="fruitCopy" data-fruit-copied-message="{{ __('Copied') }}" data-fruit-failed-message="{{ __('Could not copy') }}">
    <button type="button" {{ $attributes->merge(['data-fruit-copy' => $value] + ($iconOnly ? ['aria-label' => __('Copy')] : []))->class([
        'f-button',
        'f-button--primary' => $variant === 'primary',
        'f-button--ghost' => $variant === 'ghost',
        'f-button--small' => $size === 'small',
        'f-button--large' => $size === 'large',
        'f-button--icon' => $iconOnly,
    ]) }}>
        <svg class="f-icon f-copy__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V6a1 1 0 0 1 1-1h9" /></svg>
        <svg class="f-icon f-copy__done-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
        @unless ($iconOnly)<span class="f-copy__label">{{ $slot }}</span><span class="f-copy__done">{{ __('Copied') }}</span>@endunless
    </button>
    <span class="f-sr-only" role="status"></span>
</span>
