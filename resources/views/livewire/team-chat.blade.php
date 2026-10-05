{{-- A mailbox's team chat (App\Livewire\TeamChat), as FruitUI's Support example's room. --}}
{{-- Sending (a submit) takes the history to the newest message (FruitUI's history). The bar's
     search (mailboxes/team_chat) filters the messages shown here, in the browser: the text is
     stored encrypted. --}}
<div class="team-room" x-data="{ query: '', has: (element, query) => [...element.querySelectorAll('li[data-search]')].some((item) => item.dataset.search.includes(query)) }" x-on:team-search.window="query = $event.detail.trim().toLocaleLowerCase()">
    <x-fruit::history :aria-label="__(':mailbox Team Chat', ['mailbox' => $mailbox->name])" class="team-room__history">
        @forelse ($sections as $section_key => $section)
            <div class="team-room__section" wire:key="team-section-{{ $section_key }}" x-show="!query || has($el, query)">
            @if ($section['new'])
                <x-fruit::divider tone="accent" :aria-label="__('New Messages')">{{ $section['label'] }}</x-fruit::divider>
            @else
                <x-fruit::divider>{{ $section['label'] }}</x-fruit::divider>
            @endif
            <x-fruit::thread density="compact" :aria-label="$section['label']">
                @foreach ($section['messages'] as $item)
                    @php
                        $team_message = $item['message'];
                        $team_author = $team_message->user;
                    @endphp
                    <li wire:key="team-message-{{ $team_message->id }}" data-search="{{ mb_strtolower(($team_author ? $team_author->getFullName() : '').' '.$team_message->body.' '.$team_message->attachments->pluck('file_name')->implode(' ')) }}" x-show="!query || $el.dataset.search.includes(query)">
                        @php $team_author_name = $team_author ? $team_author->getFullName() : ''; @endphp
                        <x-fruit::message :continued="$item['continued']" id="team-message-{{ $team_message->id }}" tabindex="-1" data-team-message="{{ $team_message->id }}" :datetime="$team_message->created_at->toIso8601String()" :aria-label="$team_message->pinned_at ? __('Message from :name, pinned', ['name' => $team_author_name]) : __('Message from :name', ['name' => $team_author_name])">
                            <x-slot:avatar>
                                @if ($team_author && $team_author->photo_url)
                                    <x-fruit::avatar :src="$team_author->getPhotoUrl()" />
                                @else
                                    <x-fruit::avatar>{{ $team_author ? $team_author->getInitials() : '' }}</x-fruit::avatar>
                                @endif
                            </x-slot:avatar>
                            <x-slot:author>{{ $team_author_name }}</x-slot:author>
                            <x-slot:time><span title="{{ App\User::dateFormat($team_message->created_at) }}">{{ App\User::dateFormat($team_message->created_at, 'H:i') }}</span>@if ($team_message->pinned_at) <x-icon.pin class="f-icon team-room__pinned" aria-hidden="true" />@endif</x-slot:time>
                            <x-slot:actions>
                                <x-fruit::button variant="ghost" size="small" class="f-button--icon" wire:click="togglePin({{ $team_message->id }})" :aria-pressed="$team_message->pinned_at ? 'true' : 'false'" :aria-label="__('Pin Message')" :title="__('Pin Message')"><x-icon.pin class="f-icon" aria-hidden="true" /></x-fruit::button>
                            </x-slot:actions>
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
            </div>
        @empty
            <x-fruit::empty-state class="team-room__empty">
                <x-slot:icon><x-icon.messages-square /></x-slot:icon>
                <x-slot:title>{{ __('A Room for the Team') }}</x-slot:title>
                {{ __('Everyone who works in :mailbox can read and write here.', ['mailbox' => $mailbox->name]) }}
            </x-fruit::empty-state>
        @endforelse
        <p class="f-help team-room__no-results" x-show="query && !has($root, query)" x-cloak>{{ __('No Messages Found') }}</p>
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
        <label class="f-sr-only" for="team-composer-{{ $mailbox->id }}">{{ __('Message the :mailbox Team', ['mailbox' => $mailbox->name]) }}</label>
        <x-fruit::autocomplete trigger="@">
            <textarea id="team-composer-{{ $mailbox->id }}" class="f-composer__input f-input team-room__input" rows="2" wire:model="body" placeholder="{{ __('Message the :mailbox Team', ['mailbox' => $mailbox->name]) }}" aria-describedby="team-room-help" x-on:keydown.enter="if (tallportSendKey($event, 'chat')) { $event.preventDefault(); $el.form.requestSubmit(); }"></textarea>
            <x-slot:options>
                @foreach ($members as $member)
                    @if ($member->id != auth()->id())
                        <option value="{{ '@'.App\TeamMessage::mentionName($member) }}">{{ $member->getFullName() }}</option>
                    @endif
                @endforeach
            </x-slot:options>
        </x-fruit::autocomplete>
        <div class="f-composer__footer team-room__footer">
            <span id="team-room-help" class="f-help">{{ __('Enter to send · Shift + Enter for a new line · @ to mention') }}</span>
            <input type="file" multiple hidden x-ref="files" wire:model="files">
            <x-fruit::button variant="ghost" class="f-button--icon team-room__attach" x-on:click="$refs.files.click()" :aria-label="__('Attach Files')" :title="__('Attach Files')"><x-icon.paperclip class="f-icon" aria-hidden="true" /></x-fruit::button>
            <x-fruit::button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="send,files"><x-icon.send class="f-icon" aria-hidden="true" />{{ __('Send') }}</x-fruit::button>
        </div>
    </x-fruit::composer>
</div>
