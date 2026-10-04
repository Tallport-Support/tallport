@php(\FruitUI\Support\ComponentContract::validate('list-header', $attributes))
{{-- The row above a list: a select-all control, the list's view tools, and while items are
     selected, the selection bar in place of the tools. --}}
<div {{ $attributes->class(['f-list-header']) }}>
    @isset($leading)<div {{ $leading->attributes->class(['f-list-header__leading']) }}>{{ $leading }}</div>@endisset
    <div class="f-list-header__tools">{{ $slot }}</div>
    {{ $selection ?? '' }}
</div>
