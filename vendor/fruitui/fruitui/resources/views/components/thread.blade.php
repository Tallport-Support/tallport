@php(\FruitUI\Support\ComponentContract::validate('thread', $attributes))
<ol role="list" {{ $attributes->except('role')->class(['f-thread']) }}>{{ $slot }}</ol>
