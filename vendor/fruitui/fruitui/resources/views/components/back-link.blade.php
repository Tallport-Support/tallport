@php(\FruitUI\Support\ComponentContract::validate('back-link', $attributes))
<a {{ $attributes->class(['f-button', 'f-button--ghost', 'f-back']) }}><svg class="f-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M10 3 5 8l5 5" /></svg><span>{{ $slot }}</span></a>
