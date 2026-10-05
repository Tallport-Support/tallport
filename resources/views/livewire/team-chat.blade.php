{{-- A mailbox's team chat (App\Livewire\TeamChat), as FruitUI's Support example's room. --}}
{{-- Sending (a submit) takes the history to the newest message (FruitUI's history). --}}
<div class="team-room" x-data>
    <x-fruit::history :aria-label="__(':mailbox Team Chat', ['mailbox' => $mailbox->name])" class="team-room__history">
        @forelse ($sections as $section_key => $section)
            <x-fruit::divider :tone="$section['new'] ? 'accent' : 'neutral'" wire:key="team-divider-{{ $section_key }}">{{ $section['label'] }}</x-fruit::divider>
            <x-fruit::thread density="compact" wire:key="team-day-{{ $section_key }}" :aria-label="$section['new'] ? __('New Messages') : $section['label']">
                @foreach ($section['messages'] as $item)
                    @php
                        $team_message = $item['message'];
                        $team_author = $team_message->user;
                    @endphp
                    <li wire:key="team-message-{{ $team_message->id }}">
                        <x-fruit::message :continued="$item['continued']" id="team-message-{{ $team_message->id }}" :datetime="$team_message->created_at->toIso8601String()">
                            <x-slot:avatar>
                                @if ($team_author && $team_author->photo_url)
                                    <x-fruit::avatar :src="$team_author->getPhotoUrl()" />
                                @else
                                    <x-fruit::avatar>{{ $team_author ? $team_author->getInitials() : '' }}</x-fruit::avatar>
                                @endif
                            </x-slot:avatar>
                            <x-slot:author>{{ $team_author ? $team_author->getFullName() : '' }}</x-slot:author>
                            <x-slot:time><span title="{{ App\User::dateFormat($team_message->created_at) }}">{{ App\User::dateFormat($team_message->created_at, 'H:i') }}</span></x-slot:time>
                            @if ($team_message->body !== '')
                                <p class="team-room__text">{!! $item['html'] !!}</p>
                            @endif
                            @if ($team_message->attachments->count())
                                <x-slot:attachments>
                                    @foreach ($team_message->attachments as $attachment)
                                        <x-fruit::attachment :href="$attachment->url()" target="_blank">
                                            <x-slot:leading><x-icon.paperclip class="f-icon" aria-hidden="true" /></x-slot:leading>
                                            {{ $attachment->file_name }}
                                            <x-slot:detail>{{ $attachment->getSizeName() }}</x-slot:detail>
                                        </x-fruit::attachment>
                                    @endforeach
                                </x-slot:attachments>
                            @endif
                        </x-fruit::message>
                    </li>
                @endforeach
            </x-fruit::thread>
        @empty
            <x-fruit::empty-state class="team-room__empty">
                <x-slot:icon><x-icon.messages-square /></x-slot:icon>
                <x-slot:title>{{ __('A Room for the Team') }}</x-slot:title>
                {{ __('Everyone who works in :mailbox can read and write here.', ['mailbox' => $mailbox->name]) }}
            </x-fruit::empty-state>
        @endforelse
    </x-fruit::history>

    <x-fruit::composer class="team-room__composer" x-on:submit.prevent="$wire.send()">
        @if ($files)
            <ul class="team-room__files" aria-label="{{ __('Attachments to Send') }}">
                @foreach ($files as $file_index => $file)
                    <li wire:key="team-file-{{ $file_index }}"><x-fruit::chip>{{ $file->getClientOriginalName() }}<x-slot:remove wire:click="removeFile({{ $file_index }})">{{ __('Remove :name', ['name' => $file->getClientOriginalName()]) }}</x-slot:remove></x-fruit::chip></li>
                @endforeach
            </ul>
        @endif
        @error('files.*')<x-fruit::alert tone="danger">{{ $message }}</x-fruit::alert>@enderror
        <label class="f-sr-only" for="team-message-{{ $mailbox->id }}">{{ __('Message the :mailbox Team', ['mailbox' => $mailbox->name]) }}</label>
        <x-fruit::autocomplete trigger="@">
            <textarea id="team-message-{{ $mailbox->id }}" class="f-composer__input f-input team-room__input" rows="2" wire:model="body" placeholder="{{ __('Message the :mailbox Team', ['mailbox' => $mailbox->name]) }}" aria-describedby="team-room-help" x-on:keydown.enter="if (($event.ctrlKey || $event.metaKey) && !$event.defaultPrevented && !$event.isComposing) { $event.preventDefault(); $el.form.requestSubmit(); }"></textarea>
            <x-slot:options>
                @foreach ($members as $member)
                    @if ($member->id != auth()->id())
                        <option value="{{ '@'.App\TeamMessage::mentionName($member) }}">{{ $member->getFullName() }}</option>
                    @endif
                @endforeach
            </x-slot:options>
        </x-fruit::autocomplete>
        <div class="f-composer__footer team-room__footer">
            <span id="team-room-help" class="f-help">{{ __('Ctrl+Enter to send · @ to mention') }}</span>
            <input type="file" multiple hidden x-ref="files" wire:model="files">
            <x-fruit::button variant="ghost" class="f-button--icon team-room__attach" x-on:click="$refs.files.click()" :aria-label="__('Attach Files')" :title="__('Attach Files')"><x-icon.paperclip class="f-icon" aria-hidden="true" /></x-fruit::button>
            <x-fruit::button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="send,files"><x-icon.send class="f-icon" aria-hidden="true" />{{ __('Send') }}</x-fruit::button>
        </div>
    </x-fruit::composer>
</div>
