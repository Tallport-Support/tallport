@props(['layout' => 'inline', 'variant' => 'default', 'direction' => 'incoming', 'mine' => false, 'datetime' => null])
@php(\FruitUI\Support\ComponentContract::message($layout, $variant, $direction, $mine, $attributes))
<article {{ $attributes->class(['f-message', 'f-message--stacked' => $layout === 'stacked', 'f-message--note' => $variant === 'note', 'f-message--outgoing' => $direction === 'outgoing', 'f-message--generated' => $variant === 'generated', 'f-message--mine' => $mine]) }}>
    @isset($avatar)<span class="f-message__avatar">{{ $avatar }}</span>@endisset
    <header class="f-message__header">
        <span class="f-message__identity">@isset($author)<strong class="f-message__author">{{ $author }}</strong>@endisset @isset($meta)<small class="f-message__meta">{{ $meta }}</small>@endisset @isset($headers)<span {{ $headers->attributes->class(['f-message__headers']) }}>{{ $headers }}</span>@endisset</span>
        @isset($time)<time class="f-message__time" @if($datetime !== null) datetime="{{ $datetime }}" @endif>{{ $time }}</time>@endisset
    </header>
    <div class="f-message__body">{{ $slot }}</div>
    @isset($attachments)<div {{ $attachments->attributes->class(['f-message__attachments']) }}>{{ $attachments }}</div>@endisset
    @isset($footer)<footer {{ $footer->attributes->class(['f-message__footer']) }}>{{ $footer }}</footer>@endisset
    @isset($actions)<div {{ $actions->attributes->merge(['aria-label' => __('Message actions')])->class(['f-message__actions']) }} role="group">{{ $actions }}</div>@endisset
</article>
