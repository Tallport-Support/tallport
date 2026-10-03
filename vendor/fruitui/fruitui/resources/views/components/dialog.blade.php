@props(['name' => null])
@php($bound = \FruitUI\Support\ComponentContract::dialog($name, $attributes))
<dialog {{ $attributes->class(['f-dialog'])->merge(['data-fruit-dialog' => $name]) }} wire:ignore.self @if($bound) x-data="fruitDialogModel" x-modelable="open" @endif>{{ $slot }}</dialog>
