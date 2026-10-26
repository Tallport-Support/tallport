{{-- Who else has a conversation open, in its list row: "Noah is replying · Mia is viewing", replying
     first, quiet unless someone writes a reply (typing in its composer). public/js/realtime.js keeps
     it current from data-viewers. $presence_viewers: App\Conversation::getViewersInfo()'s users. --}}
@php
    $presence = collect($presence_viewers ?? [])->map(fn ($viewer) => ['id' => $viewer['user']->id, 'first' => $viewer['user']->getFirstName(), 'replying' => (bool) $viewer['replying']]);
@endphp
<span class="conv-presence" data-viewers="{{ $presence->toJson() }}" @if ($presence->contains('replying', true)) data-replying @endif @if ($presence->isEmpty()) hidden @endif><x-icon.eye class="f-icon conv-presence__viewing" aria-hidden="true" /><x-icon.square-pen class="f-icon conv-presence__replying" aria-hidden="true" /><span class="conv-presence__text">{{ $presence->map(fn ($viewer) => __($viewer['replying'] ? ':user is replying' : ':user is viewing', ['user' => $viewer['first']]))->implode(' · ') }}</span></span>
