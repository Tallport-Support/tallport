{{-- The inside of an Item Row or Item Link: the same slots and classes for buttons and links. --}}
@isset($leading)<span {{ $leading->attributes->class(['f-item-row__leading']) }}>{{ $leading }}</span>@endisset
<span class="f-item-row__top">
    <span class="f-item-row__title">{{ $title ?? $slot }}</span>
    @isset($trailing)<span {{ $trailing->attributes->class(['f-item-row__time']) }}>{{ $trailing }}</span>@endisset
</span>
@isset($subtitle)<span {{ $subtitle->attributes->class(['f-item-row__subtitle']) }}>{{ $subtitle }}</span>@endisset
@isset($preview)<span {{ $preview->attributes->class(['f-item-row__preview']) }}>{{ $preview }}</span>@endisset
@isset($meta)<span {{ $meta->attributes->class(['f-item-row__meta']) }}>{{ $meta }}</span>@endisset
@if (!empty($markLabel))<span class="f-sr-only">{{ $markLabel }}</span>@endif
