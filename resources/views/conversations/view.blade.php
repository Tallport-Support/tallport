@extends('layouts.app')

@section('title_full', '#'.$conversation->number.' '.$conversation->getSubject().($customer ? ' - '.$customer->getFullName(true) : ''))

@if (app('request')->input('print'))
    @section('body_class', 'body-conv print')
@else
    @section('body_class', 'body-conv')
@endif

@section('body_attrs')@parent data-conversation_id="{{ $conversation->id }}" data-mailbox_id="{{ $conversation->mailbox_id }}" data-folder_id="{{ $folder->id }}"@endsection
{{-- Its address beside the folder shown (App\Misc\Addresses), whatever address opened it (a folder's,
     the link to share, an older one): reload and Back come back here. And a message to scroll to. --}}
@section('body_attrs')@parent data-page-url="{{ App\Misc\Addresses::forConversation($conversation, $folder, request()->attributes->get('focus_thread_id')) }}" @if (request()->attributes->get('focus_thread_id')) data-focus-thread="{{ request()->attributes->get('focus_thread_id') }}" @endif@endsection

@if (!empty($list))
    {{-- The folder's conversations beside the conversation. --}}
    @section('split_class', 'split-view--open')
    @section('list_toolbar')
        <livewire:conversation-list-toolbar :folder="$folder" />
    @endsection
    @section('list')
        @include('mailboxes/partials/list_search', ['mailbox' => $list['mailbox']])
        <livewire:conversation-list :conversations="$list['conversations']" :folder="$folder" :mailbox="$list['mailbox']" :params="$list['params'] + ['current_conversation_id' => $conversation->id]" page-param="list_page" />
    @endsection
@endif

@section('toolbar')
    <livewire:conversation-toolbar :conversation="$conversation" />
@endsection

@section('inspector_label', __('Customer'))
@section('inspector_toolbar')
    <button type="button" class="f-button f-button--ghost f-button--icon app-inspector-back" x-data x-on:click="$el.closest('.app-workspace').dataset.view = ''" aria-label="{{ __('Back') }}" title="{{ __('Back') }}"><x-icon.chevron-left class="f-icon" aria-hidden="true" /></button>
    <h2 class="app-inspector-title">{{ __('Customer') }}</h2>
@endsection

@section('inspector')
    <livewire:conversation-inspector :conversation="$conversation" :folder="$folder" />
@endsection

@section('content')
    @include('partials/flash_messages')

    <livewire:conversation-pane :conversation="$conversation" :folder="$folder" />
@endsection
