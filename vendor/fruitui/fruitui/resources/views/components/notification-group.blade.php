@props(['heading'])
@php(\FruitUI\Support\ComponentContract::validate('notification-group', $attributes))
<h3 class="f-notifications__heading">{{ $heading }}</h3>
<ol {{ $attributes->class(['f-notifications'])->merge(['aria-label' => $heading]) }}>{{ $slot }}</ol>
