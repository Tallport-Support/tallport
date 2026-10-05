@props(['density' => 'comfortable'])
@php(\FruitUI\Support\ComponentContract::validate('thread', $attributes, ['density' => $density]))
<ol role="list" {{ $attributes->except('role')->class(['f-thread', 'f-thread--compact' => $density === 'compact']) }}>{{ $slot }}</ol>
