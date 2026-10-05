{{-- A team chat's details (App\Livewire\TeamChatDetails): people, pinned messages, recent files. --}}
<div class="team-details">
    <section class="inspector-section">
        <h3>{{ __('People') }} <span class="f-muted">{{ count($members) }}</span></h3>
        <ul class="team-details__list team-details__people">
            @foreach ($members as $member)
                <li>
                    @if ($member->photo_url)
                        <x-fruit::avatar :src="$member->getPhotoUrl()" />
                    @else
                        <x-fruit::avatar>{{ $member->getInitials() }}</x-fruit::avatar>
                    @endif
                    <span>{{ $member->getFullName() }}</span>
                    @if ($member->id == auth()->id())
                        <small class="f-muted">{{ __('You') }}</small>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section class="inspector-section">
        <h3>{{ __('Pinned') }} @if (count($pinned))<span class="f-badge">{{ count($pinned) }}</span>@endif</h3>
        @if (count($pinned))
            <ul class="team-details__list">
                @foreach ($pinned as $pinned_message)
                    {{-- The message in the room, in view (the room in place of these details when narrow). --}}
                    <li wire:key="pinned-{{ $pinned_message->id }}">
                        <button type="button" class="team-details__pin" x-data x-on:click="$el.closest('.app-workspace').dataset.view = ''; $nextTick(() => { let message = document.getElementById('team-message-{{ $pinned_message->id }}'); if (message) { message.scrollIntoView({block: 'center'}); message.focus({preventScroll: true}); } })">
                            <span class="team-details__pin-author"><strong>{{ $pinned_message->user ? $pinned_message->user->getFullName() : '' }}</strong> <time datetime="{{ $pinned_message->created_at->toIso8601String() }}">{{ App\Misc\Helper::dayName($pinned_message->created_at) }}, {{ App\User::dateFormat($pinned_message->created_at, 'H:i') }}</time></span>
                            <span class="team-details__pin-text">{{ $pinned_message->body !== '' ? $pinned_message->body : $pinned_message->attachments->pluck('file_name')->implode(', ') }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="f-help">{{ __('Pin a message from its actions to keep it here.') }}</p>
        @endif
    </section>

    <section class="inspector-section">
        <h3>{{ __('Recent Files') }}</h3>
        @forelse ($files as $file)
            <x-fruit::attachment :href="$file->url()" target="_blank" wire:key="file-{{ $file->id }}">
                <x-slot:leading><x-icon.paperclip class="f-icon" aria-hidden="true" /></x-slot:leading>
                {{ $file->file_name }}
                <x-slot:detail>{{ isset($authors[$file->user_id]) ? $authors[$file->user_id]->getFullName().' · ' : '' }}{{ $file->getSizeName() }}</x-slot:detail>
            </x-fruit::attachment>
        @empty
            <p class="f-help">{{ __('Files shared here appear in this list.') }}</p>
        @endforelse
    </section>
</div>
