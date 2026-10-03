@props(['variant' => 'default', 'type' => 'button', 'size' => 'regular'])
@php(\FruitUI\Support\ComponentContract::validate('button', $attributes, ['variant' => $variant, 'type' => $type, 'size' => $size]))
<button type="{{ $type }}" {{ $attributes->class([
    'f-button',
    'f-button--primary' => $variant === 'primary',
    'f-button--danger' => $variant === 'danger',
    'f-button--ghost' => $variant === 'ghost',
    'f-button--small' => $size === 'small',
    'f-button--large' => $size === 'large',
]) }}>{{ $slot }}</button>
