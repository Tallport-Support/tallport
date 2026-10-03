@php(\FruitUI\Support\ComponentContract::validate('attachment', $attributes))
{{-- A bare download attribute keeps the URL's filename; Blade would otherwise render download="download". --}}
@php($attributes = $attributes->get('download') === true ? $attributes->except('download')->merge(['download' => '']) : $attributes)
<a {{ $attributes->class(['f-attachment']) }}>
    @isset($leading)<span {{ $leading->attributes->class(['f-attachment__leading']) }}>{{ $leading }}</span>@endisset
    <span class="f-attachment__body">{{ $slot }}@isset($detail)<small {{ $detail->attributes->class(['f-attachment__detail']) }}>{{ $detail }}</small>@endisset</span>
    @isset($trailing)<span {{ $trailing->attributes->class(['f-attachment__trailing']) }}>{{ $trailing }}</span>@endisset
</a>
