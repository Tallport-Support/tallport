@props(['text' => null, 'textId' => null])
@php(\FruitUI\Support\ComponentContract::tooltip($text, $textId, $attributes))
<span {{ $attributes->class(['f-tooltip']) }} x-data="fruitTooltip">{{ $slot }}<span class="f-tooltip__text" role="tooltip" id="{{ $textId }}">{{ $text }}</span></span>
