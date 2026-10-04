@props(['title' => null, 'level' => 2, 'footer' => null])
@php([$level, $titleId] = \FruitUI\Support\ComponentContract::formSection($title, $level, $attributes))
{{-- A group of settings: a heading, a rounded box whose children are separated rows, and a footer. --}}
<section {{ $attributes->class(['f-form-section']) }} @if ($titleId) aria-labelledby="{{ $titleId }}" @endif>
    @if ($titleId)
        <h{{ $level }} class="f-form-section__title" id="{{ $titleId }}">{{ $title }}</h{{ $level }}>
    @endif
    <div class="f-form-section__rows">{{ $slot }}</div>
    @if (filled((string) $footer))
        <p class="f-form-section__footer">{{ $footer }}</p>
    @endif
</section>
