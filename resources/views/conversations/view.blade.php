@extends('layouts.app')

@php
    $is_in_chat_mode = $conversation->isInChatMode();
@endphp

@section('title_full', '#'.$conversation->number.' '.$conversation->getSubject().($customer ? ' - '.$customer->getFullName(true) : ''))

@if (app('request')->input('print'))
    @section('body_class', 'body-conv print')
@else
    @section('body_class', 'body-conv'.($is_in_chat_mode ? ' chat-mode' : ''))
@endif

@section('body_attrs')@parent data-conversation_id="{{ $conversation->id }}"@endsection

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

@if ($is_in_chat_mode)
    {{-- The chats beside the conversation. --}}
    @section('split_class', 'split-view--open')
    @section('list')
        <div class="f-pane__scroll">
            @include('mailboxes/sidebar_menu_view')
        </div>
    @endsection
@endif

@section('toolbar')
    <livewire:conversation-toolbar :conversation="$conversation" />
@endsection

@section('inspector_label', __('Customer'))
@section('inspector_toolbar')
    <button type="button" class="f-button f-button--ghost f-button--icon app-inspector-back" x-data x-on:click="$el.closest('.app-workspace').dataset.view = ''" aria-label="{{ __('Back') }}" title="{{ __('Back') }}"><x-heroicon-o-chevron-left class="f-icon" aria-hidden="true" /></button>
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
                    <p class="conv-heading__mailbox"><x-heroicon-o-envelope class="f-icon" aria-hidden="true" /><span>{{ $mailbox->name }}@if ($mailbox->email) · {{ $mailbox->email }}@endif</span></p>
                    @if ($conversation->isChat() && $conversation->getChannelName())
                            <span class="conv-tags f-row">
                                @if (\Helper::isChatMode())<a class="f-button f-button--small" href="{{ request()->fullUrlWithQuery(['chat_mode' => '0']) }}" title="{{ __('Exit') }}"><x-heroicon-s-stop class="f-icon" aria-hidden="true" /> {{ __('Chat Mode') }}</a>@else<a class="f-button f-button--small f-button--primary" href="{{ request()->fullUrlWithQuery(['chat_mode' => '1']) }}"><x-heroicon-s-play class="f-icon" aria-hidden="true" /> {{ __('Chat Mode') }}</a>@endif
                                <x-fruit::badge>{{ $conversation->getChannelName() }}</x-fruit::badge>
                            </span>
                        @endif
                    @action('conversation.after_subject', $conversation, $mailbox)
                </header>
                @if ($is_in_chat_mode)
                    <div class="conv-top-block conv-top-chat clearfix">
                        @if ($conversation->user_id != Auth::user()->id)
                            <button type="button" class="f-button f-button--small f-button--primary chat-accept" x-data="tallportChatAction({action: 'conversation_change_user', user_id: {{ Auth::user()->id }}, conversation_id: {{ $conversation->id }}})" x-on:click="run($el)">{{ __('Accept Chat') }}</button>
                        @elseif (!$conversation->isClosed())
                            <button type="button" class="f-button f-button--small chat-end" x-data="tallportChatAction({action: 'conversation_change_status', status: {{ App\Conversation::STATUS_CLOSED }}, conversation_id: {{ $conversation->id }}})" x-on:click="run($el)">{{ __('End Chat') }}</button>
                        @endif
                        <a href="#conv-top-blocks" data-toggle="collapse">{{ __('Show Details') }} <b class="caret"></b></a>
                    </div>
                    <div class="collapse" id="conv-top-blocks">
                @endif
                    @action('conversation.after_subject_block', $conversation, $mailbox)
                @if ($conversation->isInChatMode())
                    </div>
                @endif
                <div class="conv-action-wrapper">
                    <div class="conv-block conv-reply-block conv-action-block hidden">
                        <div>
                            <x-fruit::composer class="form-reply conv-composer" method="POST" action="">
                                {{ csrf_field() }}
                                <input type="hidden" name="conversation_id" value="{{ $conversation->id }}"/>
                                <input type="hidden" name="mailbox_id" value="{{ $mailbox->id }}"/>
                                <input type="hidden" name="saved_reply_id" value=""/>
                                {{-- For drafts --}}
                                <input type="hidden" name="thread_id" value=""/>
                                <input type="hidden" name="is_note" value=""/>
                                <input type="hidden" name="subtype" value=""/>
                                <input type="hidden" name="conv_history" value=""/>

                                <div class="f-composer__header conv-composer__header">
                                @if (count($from_aliases))
                                    <div class="form-group conv-from-alias">
                                        <label class="control-label">{{ __('From') }}</label>

                                        <div class="conv-reply-field">
                                            <select name="from_alias" class="f-input">
                                                @foreach ($from_aliases as $from_alias_email => $from_alias_name)
                                                    <option value="@if ($from_alias_email != $mailbox->email){{ $from_alias_email }}@endif" @if (!empty($from_alias) && $from_alias == $from_alias_email)selected="selected"@endif>@if ($from_alias_name){{ $from_alias_email }} ({{ $from_alias_name }})@else{{ $from_alias_email }}@endif</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                @endif

                                <div class="form-group{{ $errors->has('to') ? ' has-error' : '' }} conv-recipient conv-recipient-to @if (empty($to_customers)) hidden @endif">
                                    <label for="to" class="control-label">{{ __('To') }}</label>

                                    <div class="conv-reply-field">
                                        @if (!empty($to_customers))
                                            <select name="to" id="to" class="f-input">
                                                @foreach ($to_customers as $to_customer)
                                                    <option value="{{ $to_customer['email'] }}" @if ($to_customer['email'] == $conversation->customer_email)selected="selected"@endif>{{ $to_customer['customer']->getFullName(true) }} &lt;{{ $to_customer['email'] }}&gt;</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        <select class="f-input hidden parsley-exclude draft-changer" name="to_email[]" id="to_email" multiple required autofocus>
                                        </select>
                                        @include('partials/field_error', ['field'=>'to'])
                                    </div>
                                </div>

                                <div class="form-group{{ $errors->has('cc') ? ' has-error' : '' }} @if (!$cc) hidden @endif field-cc conv-recipient">
                                    <label for="cc" class="control-label">{{ __('Cc') }}</label>

                                    <div class="conv-reply-field">

                                        <select class="f-input recipient-select" name="cc[]" id="cc" multiple>
                                            @if ($cc)
                                                @foreach ($cc as $cc_email)
                                                    <option value="{{ $cc_email }}" selected="selected">{{ $cc_email }}</option>
                                                @endforeach
                                            @endif
                                        </select>

                                        @include('partials/field_error', ['field'=>'cc'])
                                    </div>
                                </div>

                                <div class="form-group{{ $errors->has('bcc') ? ' has-error' : '' }} @if (!$bcc) hidden @endif field-cc conv-recipient">
                                    <label for="bcc" class="control-label">{{ __('Bcc') }}</label>

                                    <div class="conv-reply-field">
                                         <select class="f-input recipient-select" name="bcc[]" id="bcc" multiple>
                                            @if ($bcc)
                                                @foreach ($bcc as $bcc_email)
                                                    <option value="{{ $bcc_email }}" selected="selected">{{ $bcc_email }}</option>
                                                @endforeach
                                            @endif
                                        </select>

                                        @include('partials/field_error', ['field'=>'bcc'])
                                    </div>
                                </div>

                                <div class="form-group cc-toggler @if (empty($to_customers) && !$cc && !$bcc) cc-shifted @endif @if ($cc && $bcc) hidden @endif">
                                    <label class="control-label"></label>
                                    <div class="conv-reply-field">
                                        <a href="#" class="help-link" id="toggle-cc">Cc/Bcc</a>
                                    </div>
                                </div>
                                </div>

                                @if (!empty($threads[0]) && $threads[0]->type == App\Thread::TYPE_NOTE && $threads[0]->created_by_user_id != Auth::user()->id && $threads[0]->created_by_user)
                                    <div class="f-alert f-alert--warning alert-switch-to-note">
                                        {!! __safe_raw_html('This reply will go to the customer. :%switch_start%Switch to a note:%switch_end% if you are replying to :user_name.', ['%switch_start%' => '<a href="#" class="switch-to-note">', '%switch_end%' => '</a>', 'user_name' => htmlspecialchars($threads[0]->created_by_user->getFullName()) ]) !!}
                                    </div>
                                @endif

                                <div class="thread-attachments attachments-upload form-group">
                                    <ul></ul>
                                </div>

                                <div class="form-group{{ $errors->has('body') ? ' has-error' : '' }} conv-reply-body">
                                    <x-editor id="body" name="body" rows="8" :paste="$conversation->isChat() ? 'plain' : 'rich'" :upload-url="route('conversations.upload')" :aria-label="__('Message')" data-parsley-required="true" :data-parsley-required-message="__('Please enter a message')" :placeholder="$conversation->isInChatMode() ? __('Use ENTER to send the message and SHIFT+ENTER for a new line') : null">
                                        {{ old('body', $conversation->body) }}
                                        <x-slot:extras>
                                            @include('conversations/partials/editor_extras')
                                        </x-slot:extras>
                                    </x-editor>
                                    @include('partials/field_error', ['field'=>'body'])
                                </div>

                                @include('conversations/editor_bottom_toolbar')
                            </x-fruit::composer>
                        </div>
                        @if (App\Ai\Drafts::allowed(Auth::user(), $conversation))
                            @include('conversations/partials/ai_draft_panel')
                        @endif
                        @action('reply_form.after', $conversation)
                    </div>
                </div>
            </div>
        </div>

        <div class="conv-thread">
            @action('conversation.before_threads', $conversation)
            <x-fruit::thread id="conv-layout-main" :aria-label="__('Conversation History')">
                @include('conversations/partials/ai_summary')
                @include('conversations/partials/threads')
            </x-fruit::thread>
            @action('conversation.after_threads', $conversation)
        </div>
    </div>
@endsection

@section('body_bottom')
    @parent
    @include('conversations.partials.settings_modal', ['conversation' => $conversation])
@append


@section('javascript')
    @parent
    initReplyForm();
    initConversation();
    aiDraftsInit();
@endsection
