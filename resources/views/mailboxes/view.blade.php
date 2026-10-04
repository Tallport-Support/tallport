@extends('layouts.app')

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection
@section('body_attrs')@parent data-folder_id="{{ $folder->id }}"@endsection
{{-- Its scripts are ready for wire:navigate (the sidebar's folder links). --}}
@section('body_attrs')@parent data-navigable @endsection

@if ($folder->active_count)
    @section('title', '('.(int)$folder->getCount().') '.$folder->getTypeName().' - '.$mailbox->name)
@else
    @section('title', $folder->getTypeName().' - '.$mailbox->name)
@endif


@section('main_class', 'fruit-ui')

@section('list_toolbar')
    @include('mailboxes/partials/list_toolbar')
@endsection

@section('list')
    @include('mailboxes/partials/list_search')
    <div class="alerts">
        @php
            $flashes = \Helper::maybeShowSendingProblemsAlert();
        @endphp
        @include('partials/flash_messages')
    </div>
    <livewire:conversation-list :conversations="$conversations" :folder="$folder" :mailbox="$mailbox" :params="$params ?? []" page-param="page" />
@endsection

@section('content')
    <x-fruit::empty-state class="split-view__empty">
        <x-slot:icon><x-icon.mail-open /></x-slot:icon>
        <x-slot:title>{{ __('No conversation selected') }}</x-slot:title>
        {{ __('Choose a conversation from the list.') }}
    </x-fruit::empty-state>
@endsection
