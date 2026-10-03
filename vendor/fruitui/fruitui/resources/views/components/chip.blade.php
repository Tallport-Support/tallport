@php(\FruitUI\Support\ComponentContract::validate('chip', $attributes))
<span {{ $attributes->class(['f-chip']) }}><span>{{ $slot }}</span>@isset($remove)<button type="button" {{ $remove->attributes->except(['type', 'aria-label'])->class(['f-chip__remove']) }} aria-label="{{ trim(strip_tags($remove)) }}">×</button>@endisset</span>
