@props(['title'])
@php(\FruitUI\Support\ComponentContract::validate('disclosure', $attributes))
<details {{ $attributes->class(['f-disclosure']) }}>
    <summary>{{ $title }}</summary>
    <div>{{ $slot }}</div>
</details>
