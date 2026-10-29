@php(\FruitUI\Support\ComponentContract::validate('more', $attributes))
{{-- Long content shown as its first few lines of text (--f-more-lines, 4), faded at the last, with
     Show More for the rest. Content only a line or two longer shows in full, and so does all of it
     until fruitMore has measured it. --}}
<div {{ $attributes->class(['f-more']) }} x-data="fruitMore" x-bind:data-overflowing="overflowing ? '' : null" x-bind:style="{ '--f-more-height': height + 'px' }">
    <div class="f-more__content">{{ $slot }}</div>
    <button type="button" class="f-more__toggle" aria-expanded="false" x-bind:aria-expanded="showingAll ? 'true' : 'false'" x-on:click="toggle()"><span class="f-more__show">{{ __('Show More') }}</span><span class="f-more__hide">{{ __('Show Less') }}</span><svg class="f-icon f-more__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
</div>
