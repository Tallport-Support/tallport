@props(['layout' => 'inline', 'variant' => 'default', 'direction' => 'incoming', 'mine' => false, 'datetime' => null])
@php(\FruitUI\Support\ComponentContract::message($layout, $variant, $direction, $mine, $attributes))
<article {{ $attributes->class(['f-message', 'f-message--stacked' => $layout === 'stacked', 'f-message--note' => $variant === 'note', 'f-message--outgoing' => $direction === 'outgoing', 'f-message--generated' => $variant === 'generated', 'f-message--mine' => $mine]) }}>
    @isset($avatar)<span class="f-message__avatar">{{ $avatar }}</span>@endisset
    <header class="f-message__header">
        <span class="f-message__identity">@isset($author)<strong class="f-message__author">{{ $author }}</strong>@endisset @isset($meta)<small class="f-message__meta">{{ $meta }}</small>@endisset @isset($headers)<span {{ $headers->attributes->class(['f-message__headers']) }}>{{ $headers }}</span>@endisset</span>
        @isset($time)<time class="f-message__time" @if($datetime !== null) datetime="{{ $datetime }}" @endif>{{ $time }}</time>@endisset
    </header>
    @isset($translation)<div {{ $translation->attributes->class(['f-message__translation']) }}><svg class="f-icon f-message__translation-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/></svg><span class="f-sr-only">{{ __('Translation') }}</span><div class="f-message__translation-text">{{ $translation }}</div></div>@endisset
    <div class="f-message__body">{{ $slot }}</div>
    @isset($attachments)<div {{ $attachments->attributes->class(['f-message__attachments']) }}>{{ $attachments }}</div>@endisset
    @isset($footer)<footer {{ $footer->attributes->class(['f-message__footer']) }}>{{ $footer }}</footer>@endisset
    @isset($actions)<div {{ $actions->attributes->merge(['aria-label' => __('Message actions')])->class(['f-message__actions']) }} role="group">{{ $actions }}</div>@endisset
</article>
