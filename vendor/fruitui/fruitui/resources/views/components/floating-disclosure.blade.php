@props(['title' => null, 'placement' => 'below'])
@php(\FruitUI\Support\ComponentContract::validate('floating-disclosure', $attributes, ['placement' => $placement]))
<details {{ $attributes->class(['f-floating-disclosure', 'f-floating-disclosure--above' => $placement === 'above']) }}>
    <summary @isset($trigger) {{ $trigger->attributes }} @endisset>{{ $trigger ?? $title }}</summary>
    <div @isset($content) {{ $content->attributes->class(['f-floating-disclosure__content']) }} @else class="f-floating-disclosure__content" @endisset>{{ $content ?? $slot }}</div>
</details>
