@props(['selection' => 'none'])
@php(\FruitUI\Support\ComponentContract::validate('item-list', $attributes, ['selection' => $selection]))
@if ($selection === 'multiple')@php(\FruitUI\Support\ComponentContract::validate('selectable-item-list', $attributes))@endif
{{-- selection="multiple": items' checkboxes stay hidden; Cmd/Ctrl+click, Shift+click and Shift+arrows check them. --}}
<ul role="list" {{ $attributes->except('role')->class(['f-item-list'])->merge($selection === 'multiple' ? ['data-fruit-selection' => 'multiple', 'x-data' => 'fruitListSelection'] : []) }}>{{ $slot }}</ul>
