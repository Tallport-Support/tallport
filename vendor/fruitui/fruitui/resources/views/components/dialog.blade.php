@props(['name' => null, 'size' => 'medium'])
@php($bound = \FruitUI\Support\ComponentContract::dialog($name, $attributes, $size))
<dialog {{ $attributes->class(['f-dialog', 'f-dialog--large' => $size === 'large'])->merge(['data-fruit-dialog' => $name]) }} wire:ignore.self @if($bound) x-data="fruitDialogModel" x-modelable="open" @endif>{{ $slot }}</dialog>
