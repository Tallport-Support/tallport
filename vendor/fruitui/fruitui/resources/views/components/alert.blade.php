@props(['tone' => 'info'])
@php(\FruitUI\Support\ComponentContract::validate('alert', $attributes, ['tone' => $tone]))
<div {{ $attributes->class(['f-alert', 'f-alert--'.$tone]) }}>
    @isset($icon)<span class="f-alert__icon" aria-hidden="true">{{ $icon }}</span>@endisset
    <div class="f-alert__body">{{ $slot }}</div>
    @isset($actions)<div class="f-alert__actions">{{ $actions }}</div>@endisset
</div>
