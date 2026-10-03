@php(\FruitUI\Support\ComponentContract::validate('toolbar', $attributes))
<div {{ $attributes->class(['f-toolbar']) }}>
    {{ $slot }}
    @isset($actions)<div class="f-toolbar__spacer"></div><div {{ $actions->attributes->class(['f-toolbar__group']) }}>{{ $actions }}</div>@endisset
</div>
