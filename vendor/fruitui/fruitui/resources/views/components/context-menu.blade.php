@props(['title' => __('Actions')])
@php(\FruitUI\Support\ComponentContract::validate('context-menu', $attributes))
<div {{ $attributes->except(['role'])->class(['f-menu__items', 'f-context-menu']) }} role="menu" aria-label="{{ $title }}" x-data="fruitContextMenu" wire:ignore.self hidden>{{ $slot }}</div>
