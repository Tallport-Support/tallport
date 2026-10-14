@props(['title', 'variant' => 'box'])
@php(\FruitUI\Support\ComponentContract::validate('disclosure', $attributes, ['variant' => $variant]))
<details {{ $attributes->class(['f-disclosure', 'f-disclosure--quiet' => $variant === 'quiet']) }}>
    <summary>{{ $title }}</summary>
    <div>{{ $slot }}</div>
</details>
