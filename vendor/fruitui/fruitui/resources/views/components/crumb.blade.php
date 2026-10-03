@props(['current' => false])
@php(\FruitUI\Support\ComponentContract::crumb($current, $attributes))
<li>@if($current)<span aria-current="page" {{ $attributes }}>{{ $slot }}</span>@else<a {{ $attributes }}>{{ $slot }}</a>@endif</li>
