@props(['title' => null, 'description' => null, 'width' => 'medium', 'align' => 'center', 'level' => 1])
@php($level = \FruitUI\Support\ComponentContract::page($width, $level, $attributes, $align))
{{-- A page's content column, centered in its pane (or from its start, for settings): an optional
     header (a Back Link to the parent page, then the title), optional section tabs, the content, and
     an optional footer (a save bar) that stays in view, all aligned to one width. --}}
<div {{ $attributes->class(['f-page', 'f-page--narrow' => $width === 'narrow', 'f-page--wide' => $width === 'wide', 'f-page--start' => $align === 'start']) }}>
    @if ($title !== null || $description !== null || isset($actions) || isset($back))
        <header class="f-page__header">
            <div class="f-page__heading">
                @isset($back){{ $back }}@endisset
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
