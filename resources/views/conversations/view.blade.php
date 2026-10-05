@extends('layouts.app')

@section('title_full', '#'.$conversation->number.' '.$conversation->getSubject().($customer ? ' - '.$customer->getFullName(true) : ''))

@if (app('request')->input('print'))
    @section('body_class', 'body-conv print')
@else
    @section('body_class', 'body-conv')
@endif

@section('body_attrs')@parent data-conversation_id="{{ $conversation->id }}"@endsection
{{-- Shown at a folder's URL (ConversationsController::openFolder()): the conversation's own. --}}
@if (!Route::is('conversations.view'))
    @section('body_attrs')@parent data-page-url="{{ route('conversations.view', ['id' => $conversation->id, 'folder_id' => $folder->id]) }}"@endsection
@endif

@if (!empty($list))
    {{-- The folder's conversations beside the conversation. --}}
    @section('split_class', 'split-view--open')
    @section('list_toolbar')
        @include('mailboxes/partials/list_toolbar', ['folder' => $folder, 'mailbox' => $list['mailbox'], 'conversations' => $list['conversations']])
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
        <div id="conv-layout-customer">
            @include('conversations/partials/customer_sidebar')
            @action('conversation.after_customer_sidebar', $conversation)
        </div>
@endsection

@section('content')
    @include('partials/flash_messages')

    <div id="conv-layout" class="conv-type-{{ strtolower($conversation->getTypeName()) }}">
        <div id="conv-layout-header">
            <div id="conv-subject">
                <header class="conv-heading">
                    <livewire:conversation-subject :conversation="$conversation" :viewers="$viewers" />
                    @if ($customer)
                        <p>{{ $customer->getFullName(true) }}@if ($conversation->customer_email && $conversation->customer_email != $customer->getFullName(true)) · {{ $conversation->customer_email }}@endif</p>
                    @endif
                    {{-- How the conversation reaches the mailbox: its address, or a chat channel (Nostr, Telegram). --}}
                    @if ($conversation->hasChannel() && $conversation->getChannelName())
                        <p class="conv-heading__mailbox"><x-icon.message-circle class="f-icon" aria-hidden="true" /><span>{{ $mailbox->name }} · {{ $conversation->getChannelName() }}</span></p>
                    @else
                        <p class="conv-heading__mailbox"><x-icon.mail class="f-icon" aria-hidden="true" /><span>{{ $mailbox->name }}@if ($mailbox->email) · {{ $mailbox->email }}@endif</span></p>
                    @endif
                    @action('conversation.after_subject', $conversation, $mailbox)
                </header>
                @action('conversation.after_subject_block', $conversation, $mailbox)
                <livewire:conversation-composer :conversation="$conversation" :to-customers="$to_customers" :cc="$cc" :from-aliases="$from_aliases" :from-alias="$from_alias" :after-send="$after_send" />
            </div>
        </div>

        <div class="conv-thread">
            @action('conversation.before_threads', $conversation)
            <livewire:conversation-thread :conversation="$conversation" :threads="$threads" />
            @action('conversation.after_threads', $conversation)
        </div>
    </div>
@endsection




