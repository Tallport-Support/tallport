@extends('layouts.app')

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection
@section('body_attrs')@parent data-folder_id="{{ $folder->id }}"@endsection

@if ($folder->active_count)
    @section('title', '('.(int)$folder->getCount().') '.$folder->getTypeName().' - '.$mailbox->name)
@else
    @section('title', $folder->getTypeName().' - '.$mailbox->name)
@endif


@section('main_class', 'fruit-ui')

@section('list')
    {{-- The folder and its mailbox. --}}
    <x-page-nav class="split-view__header">
        <x-slot:title><h1>{{ $folder->getTypeName() }}</h1><small class="f-muted">@include('mailboxes/partials/mute_icon', ['mailbox' => $mailbox]){{ $mailbox->name }}</small></x-slot:title>
        <x-slot:actions>
            @if (($folder->type == App\Folder::TYPE_DELETED || $folder->type == App\Folder::TYPE_SPAM) && $folder->total_count)
                <a href="#" class="f-button f-button--small f-button--danger mailbox-empty-folder">@if ($folder->type == App\Folder::TYPE_DELETED){{ __('Empty Trash') }}@else{{ __('Delete All') }}@endif</a>
            @endif
        </x-slot:actions>
    </x-page-nav>
    <div class="alerts">
        @php
            $flashes = \Helper::maybeShowSendingProblemsAlert();
        @endphp
        @include('partials/flash_messages')
    </div>
    @include('conversations/conversations_table')
@endsection

@section('content')
    <x-fruit::empty-state class="split-view__empty">
        <x-slot:icon><x-heroicon-o-envelope-open /></x-slot:icon>
        <x-slot:title>{{ __('No conversation selected') }}</x-slot:title>
        {{ __('Choose a conversation from the list.') }}
    </x-fruit::empty-state>
@endsection

@section('javascript')
    @parent
    viewMailboxInit();
@endsection