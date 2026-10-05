@props(['title' => null, 'description' => null, 'width' => 'medium', 'level' => 1])
@php($level = \FruitUI\Support\ComponentContract::page($width, $level, $attributes))
{{-- A page's content column, centered in its pane: an optional header, optional section tabs, the
     content, and an optional footer (a save bar) that stays in view, all aligned to one width. --}}
<div {{ $attributes->class(['f-page', 'f-page--narrow' => $width === 'narrow', 'f-page--wide' => $width === 'wide']) }}>
    @if ($title !== null || $description !== null || isset($actions))
        <header class="f-page__header">
            <div class="f-page__heading">
                @if ($title !== null)<h{{ $level }} class="f-page__title">{{ $title }}</h{{ $level }}>@endif
                @if ($description !== null)<p class="f-page__description">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div {{ $actions->attributes->class(['f-page__actions']) }}>{{ $actions }}</div>@endisset
        </header>
    @endif
    @isset($nav)<div {{ $nav->attributes->class(['f-page__nav']) }}>{{ $nav }}</div>@endisset
    <div class="f-page__body">{{ $slot }}</div>
    @isset($footer)<footer {{ $footer->attributes->class(['f-page__footer']) }}>{{ $footer }}</footer>@endisset
</div>
