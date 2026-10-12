@props(['title' => null, 'optional' => false])
@php($titleId = \FruitUI\Support\ComponentContract::drilldownColumn($title, $optional, $attributes))
{{-- One level: a title row (a Back Link to the level before, shown while that level is hidden; the
     title, which takes focus after navigation; actions) above the level's own scrolling content. --}}
<section {{ $attributes->class(['f-drilldown__column', 'f-drilldown__column--optional' => $optional]) }} aria-labelledby="{{ $titleId }}">
    <header class="f-drilldown__header">
        @isset($back){{ $back }}@endisset
        <h2 class="f-drilldown__title" id="{{ $titleId }}" tabindex="-1">{{ $title }}</h2>
        @isset($actions)<div {{ $actions->attributes->class(['f-drilldown__actions']) }}>{{ $actions }}</div>@endisset
    </header>
    <div class="f-pane__scroll">{{ $slot }}</div>
</section>
