@extends('layouts.app')

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection
@section('body_attrs')@parent data-folder_id="{{ $folder->id }}" data-page-url="{{ App\Misc\Addresses::folderUrl($folder) }}"@endsection

@if ($sidebar_counts['folders'][$folder->id] ?? 0)
    @section('title', '('.$sidebar_counts['folders'][$folder->id].') '.$folder->getTypeName().' - '.$mailbox->name)
@else
    @section('title', $folder->getTypeName().' - '.$mailbox->name)
@endif


@section('main_class', 'fruit-ui')

{{-- Beside the list, the open conversation's parts without one (an empty folder, a later page of
     the list): a folder or a conversation opens in them in place (public/js/conversations.js). --}}
@section('split_class', 'app-workspace--empty')
@section('list_toolbar')
    <livewire:conversation-list-toolbar :folder="$folder" />
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

@section('toolbar')
    <livewire:conversation-toolbar :folder="$folder" />
@endsection

@section('inspector_label', __('Customer'))
@section('inspector_toolbar')
    <button type="button" class="f-button f-button--ghost f-button--icon app-inspector-back" x-data x-on:click="$el.closest('.app-workspace').dataset.view = ''" aria-label="{{ __('Back') }}" title="{{ __('Back') }}"><x-icon.chevron-left class="f-icon" aria-hidden="true" /></button>
    <h2 class="app-inspector-title">{{ __('Customer') }}</h2>
@endsection

@section('inspector')
    <livewire:conversation-inspector :folder="$folder" />
@endsection

@section('content')
    <livewire:conversation-pane :folder="$folder" />
@endsection
