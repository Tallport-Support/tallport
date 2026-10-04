@props(['wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('color', $attributes, $fruitField))
@php($palette = $attributes->get('list') ?? ($attributes->get('id') ?? 'fruit-color-'.\Illuminate\Support\Str::slug((string) $attributes->get('name', 'default'))).'-palette')
{{-- The native input keeps the value; the swatch palette and its custom editor replace the browser's chooser. --}}
<div {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->merge([
    'data-fruit-label' => __('Choose color'),
    'data-fruit-colors-label' => __('Colors'),
    'data-fruit-other-label' => __('Other…'),
    'data-fruit-area-label' => __('Saturation and brightness'),
    'data-fruit-area-text' => __('Saturation {saturation}%, brightness {brightness}%'),
    'data-fruit-hue-label' => __('Hue'),
    'data-fruit-hex-label' => __('Hex'),
])->class(['f-color-picker']) }} x-data="fruitColorPicker">
    <input type="color" aria-haspopup="dialog" {{ $attributes->merge(['list' => $palette])->class(['f-input f-color']) }}>
    @unless ($attributes->has('list'))
        {{-- The default system colors; Chrome's own chooser shows them too. --}}
        <datalist id="{{ $palette }}">
            <option value="#ff3b30" label="{{ __('Red') }}"></option>
            <option value="#ff9500" label="{{ __('Orange') }}"></option>
            <option value="#ffcc00" label="{{ __('Yellow') }}"></option>
            <option value="#34c759" label="{{ __('Green') }}"></option>
            <option value="#00c7be" label="{{ __('Mint') }}"></option>
            <option value="#30b0c7" label="{{ __('Teal') }}"></option>
            <option value="#32ade6" label="{{ __('Cyan') }}"></option>
            <option value="#007aff" label="{{ __('Blue') }}"></option>
            <option value="#5856d6" label="{{ __('Indigo') }}"></option>
            <option value="#af52de" label="{{ __('Purple') }}"></option>
            <option value="#ff2d55" label="{{ __('Pink') }}"></option>
            <option value="#a2845e" label="{{ __('Brown') }}"></option>
            <option value="#8e8e93" label="{{ __('Gray') }}"></option>
        </datalist>
    @endunless
    <div data-fruit-ui wire:ignore></div>
</div>
