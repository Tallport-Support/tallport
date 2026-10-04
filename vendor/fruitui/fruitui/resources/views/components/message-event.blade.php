@props(['datetime' => null])
@php(\FruitUI\Support\ComponentContract::validate('message-event', $attributes))
{{-- A thread event between messages, such as an assignment or a status change. --}}
<div {{ $attributes->class(['f-message-event']) }}>@isset($icon)<span class="f-message-event__icon" aria-hidden="true">{{ $icon }}</span>@endisset<span class="f-message-event__text">{{ $slot }}</span>@isset($time)<time class="f-message-event__time" @if ($datetime !== null) datetime="{{ $datetime }}" @endif>{{ $time }}</time>@endisset @isset($actions)<div {{ $actions->attributes->merge(['aria-label' => __('Event actions')])->class(['f-message-event__actions']) }} role="group">{{ $actions }}</div>@endisset</div>
