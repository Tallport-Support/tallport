@php(\FruitUI\Support\ComponentContract::validate('back-link', $attributes))
<a {{ $attributes->class(['f-button', 'f-button--ghost', 'f-back']) }}><svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg><span>{{ $slot }}</span></a>
