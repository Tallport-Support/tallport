@props(['layout' => 'inline', 'variant' => 'default', 'direction' => 'incoming', 'mine' => false, 'datetime' => null])
@php(\FruitUI\Support\ComponentContract::message($layout, $variant, $direction, $mine, $attributes))
<article {{ $attributes->class(['f-message', 'f-message--stacked' => $layout === 'stacked', 'f-message--note' => $variant === 'note', 'f-message--outgoing' => $direction === 'outgoing', 'f-message--generated' => $variant === 'generated', 'f-message--mine' => $mine]) }}>
    @isset($avatar)<span class="f-message__avatar">{{ $avatar }}</span>@endisset
    <header class="f-message__header">
        <span class="f-message__identity">@isset($author)<strong class="f-message__author">{{ $author }}</strong>@endisset @isset($meta)<small class="f-message__meta">{{ $meta }}</small>@endisset @isset($headers)<span {{ $headers->attributes->class(['f-message__headers']) }}>{{ $headers }}</span>@endisset</span>
        @isset($time)<time class="f-message__time" @if($datetime !== null) datetime="{{ $datetime }}" @endif>{{ $time }}</time>@endisset
    </header>
    <div class="f-message__body">{{ $slot }}</div>
    @isset($translation)<div {{ $translation->attributes->class(['f-message__translation']) }}><svg class="f-icon f-message__translation-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h9M8.5 3v2M11 5c-.7 3.6-3 6.5-6.5 8M6.5 8.5c1.2 2 3 3.6 5 4.5M13 21l4-9 4 9M14.4 18h5.2" /></svg><span class="f-sr-only">{{ __('Translation') }}</span><div class="f-message__translation-text">{{ $translation }}</div></div>@endisset
    @isset($attachments)<div {{ $attachments->attributes->class(['f-message__attachments']) }}>{{ $attachments }}</div>@endisset
    @isset($footer)<footer {{ $footer->attributes->class(['f-message__footer']) }}>{{ $footer }}</footer>@endisset
    @isset($actions)<div {{ $actions->attributes->merge(['aria-label' => __('Message actions')])->class(['f-message__actions']) }} role="group">{{ $actions }}</div>@endisset
</article>
