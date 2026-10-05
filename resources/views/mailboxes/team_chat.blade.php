@extends('layouts.app')

{{-- A mailbox's team chat (App\Livewire\TeamChat): the room in place of the list, conversation and inspector. --}}
@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}" data-team_chat="{{ $mailbox->id }}"@endsection

@section('title', __(':mailbox Team', ['mailbox' => $mailbox->name]))

@section('main_class', 'fruit-ui')
@section('no_footer', '1')

@section('toolbar')
    <div class="app-list-title">
        <h1>{{ __(':mailbox Team', ['mailbox' => $mailbox->name]) }}</h1>
        <p>{{ $members->map(fn ($member) => $member->first_name ?: $member->getFullName())->implode(', ') }}</p>
    </div>
    <span class="f-toolbar__spacer"></span>
    {{-- Filters the messages in the room (livewire/team-chat), in the browser. --}}
    <x-fruit::search :label="__('Search Messages')" :placeholder="__('Search')" class="team-room__search" x-data x-on:input="$dispatch('team-search', $event.target.value)" />
@endsection

@section('content')
    <livewire:team-chat :mailbox="$mailbox" />
@endsection
