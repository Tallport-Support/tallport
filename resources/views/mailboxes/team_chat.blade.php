@extends('layouts.app')

{{-- A mailbox's team chat (App\Livewire\TeamChat): the room in place of the list and conversation,
     its details (App\Livewire\TeamChatDetails) where the inspector is. --}}
@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}" data-team_chat="{{ $mailbox->id }}"@endsection

@section('title', __(':mailbox Team', ['mailbox' => $mailbox->name]))

@section('main_class', 'fruit-ui')
@section('no_footer', '1')

@section('toolbar')
    <div class="app-list-title team-room__title">
        <div class="team-room__heading">
            <h1>{{ __(':mailbox Team', ['mailbox' => $mailbox->name]) }}</h1>
            @if (count($rooms) > 1)
                {{-- The other rooms; the one chosen opens next time too (MailboxesController::teamChat()). --}}
                <x-fruit::menu :title="__('Team Chat')" class="team-room__switcher">
                    <x-slot:trigger class="f-button--ghost f-button--icon f-button--small" :aria-label="__('Switch Team Chat')"><x-icon.chevron-down class="f-icon" aria-hidden="true" /></x-slot:trigger>
                    @foreach ($rooms as $room)
                        @php $room_unread = $unread[$room->id] ?? 0; @endphp
                        <x-fruit::menu-radio :checked="$room->id == $mailbox->id" :data-url="route('mailboxes.team_chat', ['id' => $room->id])" x-on:click="Livewire.navigate($el.dataset.url)" :aria-label="$room_unread ? __(':mailbox, :count unread', ['mailbox' => $room->name, 'count' => $room_unread]) : null">{{ $room->name }}@if ($room_unread) <span class="f-badge f-badge--accent">{{ $room_unread }}</span>@endif</x-fruit::menu-radio>
                    @endforeach
                </x-fruit::menu>
            @endif
        </div>
        <p>{{ $members->map(fn ($member) => $member->first_name ?: $member->getFullName())->implode(', ') }}</p>
    </div>
    <span class="f-toolbar__spacer"></span>
    {{-- Filters the messages in the room (livewire/team-chat), in the browser. --}}
    <x-fruit::search :label="__('Search Messages')" :placeholder="__('Search')" class="team-room__search" x-data x-on:input="$dispatch('team-search', $event.target.value)" />
    {{-- Narrow: the details in place of the room. --}}
    <button type="button" class="f-button f-button--ghost f-button--icon app-inspector-toggle" x-data x-on:click="$el.closest('.app-workspace').dataset.view = 'inspector'; $nextTick(() => document.querySelector('.app-inspector-back')?.focus())" aria-controls="app-inspector" aria-label="{{ __('Show Chat Details') }}" title="{{ __('Show Chat Details') }}"><x-icon.info class="f-icon" aria-hidden="true" /></button>
@endsection

@section('inspector_label', __('Details'))
@section('inspector_toolbar')
    <button type="button" class="f-button f-button--ghost f-button--icon app-inspector-back" x-data x-on:click="$el.closest('.app-workspace').dataset.view = ''; $nextTick(() => document.querySelector('.app-inspector-toggle')?.focus())" aria-label="{{ __('Back to Team Chat') }}" title="{{ __('Back to Team Chat') }}"><x-icon.chevron-left class="f-icon" aria-hidden="true" /></button>
    <h2 class="app-inspector-title">{{ __('Details') }}</h2>
    <span class="f-toolbar__spacer"></span>
    <x-icon.info class="f-icon team-room__details-icon" aria-hidden="true" />
@endsection

@section('inspector')
    <livewire:team-chat-details :mailbox="$mailbox" />
@endsection

@section('content')
    <livewire:team-chat :mailbox="$mailbox" />
@endsection
