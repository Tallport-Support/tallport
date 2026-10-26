{{-- Who else has the open conversation, in its heading: their faces and "Noah Williams is replying,
     Mia Patel is viewing", in the accent while someone writes a reply. public/js/realtime.js keeps it
     current from data-viewers. $viewers: [['user' => App\User, 'replying' => bool], …], replying first. --}}
@php
    $presence = collect($viewers ?? [])->map(fn ($viewer) => [
        'id' => $viewer['user']->id, 'first' => $viewer['user']->getFirstName(), 'name' => $viewer['user']->getFullName(),
        'initials' => $viewer['user']->getInitials(), 'photo' => $viewer['user']->getPhotoUrl(false), 'replying' => (bool) $viewer['replying'],
    ]);
@endphp
<p id="conv-presence" class="conv-presence-line" data-viewers="{{ $presence->toJson() }}" @if ($presence->contains('replying', true)) data-replying @endif @if ($presence->isEmpty()) hidden @endif wire:ignore wire:key="presence-{{ $conversation->id }}">
    <span class="conv-presence-line__faces" aria-hidden="true">@foreach ($presence as $viewer)<x-fruit::avatar class="conv-presence-line__face" :src="$viewer['photo'] ?: null">{{ $viewer['initials'] }}</x-fruit::avatar>@endforeach</span>
    <span class="conv-presence-line__text">{{ $presence->map(fn ($viewer) => __($viewer['replying'] ? ':user is replying' : ':user is viewing', ['user' => $viewer['name']]))->implode(', ') }}</span>
</p>
