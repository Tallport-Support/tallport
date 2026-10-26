{{-- A teammate writing a reply here, right above the composer, so two people don't answer at once.
     Empty, it takes no room but stays a status region (the next one is read out). public/js/realtime.js
     keeps it current. $viewers: as for conversations/partials/presence_line. --}}
@php
    $writing = collect($viewers ?? [])->first(fn ($viewer) => $viewer['replying']);
    // Text, not a Blade @if: Livewire marks those blocks, and FruitUI's Typing would see a slot.
    $writing_text = $writing ? __(':user is writing a reply', ['user' => $writing['user']->getFullName()]) : '';
@endphp
<x-fruit::typing id="conv-typing" class="conv-typing" wire:ignore wire:key="typing-{{ $conversation->id }}">{{ $writing_text }}</x-fruit::typing>
