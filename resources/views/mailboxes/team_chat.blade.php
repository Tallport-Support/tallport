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
@endsection

@section('content')
    <livewire:team-chat :mailbox="$mailbox" />
@endsection
