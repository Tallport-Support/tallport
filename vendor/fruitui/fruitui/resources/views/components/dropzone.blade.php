@props(['wrapper' => []])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('dropzone', $attributes, $fruitField))
<label {{ \FruitUI\Support\ComponentContract::wrapper($wrapper)->class(['f-dropzone']) }} x-data="fruitDropzone">
    <input type="file" {{ $attributes->class(['f-dropzone__input']) }}>
    <span class="f-dropzone__label">@if($slot->isNotEmpty()){{ $slot }}@else{{ __('Drop files here or') }} <span class="f-dropzone__action">{{ __('choose files') }}</span>@endif</span>
    @isset($hint)<span class="f-dropzone__hint">{{ $hint }}</span>@endisset
</label>
