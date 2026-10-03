@props(['datetime' => null])
@php(\FruitUI\Support\ComponentContract::validate('timeline-item', $attributes))
<li {{ $attributes->class(['f-timeline__item']) }}>
    @isset($icon)<span class="f-timeline__icon" aria-hidden="true">{{ $icon }}</span>@endisset
    <div class="f-timeline__body"><strong>{{ $slot }}</strong>@isset($detail)<p>{{ $detail }}</p>@endisset</div>
    @isset($time)<time class="f-timeline__time" @if($datetime !== null) datetime="{{ $datetime }}" @endif>{{ $time }}</time>@endisset
</li>
