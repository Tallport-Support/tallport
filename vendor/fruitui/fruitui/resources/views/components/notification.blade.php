@php(\FruitUI\Support\ComponentContract::validate('notification', $attributes))
<li class="f-notifications__item">
    @isset($avatar){{ $avatar }}@endisset
    <a {{ $attributes }}>{{ $slot }}@isset($meta)<small>{{ $meta }}</small>@endisset</a>
    @isset($badge)<span {{ $badge->attributes->class(['f-badge']) }}>{{ $badge }}</span>@endisset
</li>
