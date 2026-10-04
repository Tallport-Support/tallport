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
        @include('conversations/conversations_table', ['conversations' => $list['conversations'], 'mailbox' => $list['mailbox'], 'params' => $list['params'] + ['current_conversation_id' => $conversation->id]])
    @endsection
@endif

@if ($is_in_chat_mode)
    {{-- The chats beside the conversation. --}}
    @section('split_class', 'split-view--open')
    @section('list')
        @include('mailboxes/sidebar_menu_view')
    @endsection
@endif

@section('toolbar')
    <div id="conv-toolbar" class="conv-toolbar">
                @php
                    $actions = \App\Misc\ConversationActionButtons::getActions($conversation, Auth::user(), $mailbox);
                    $toolbar_actions = \App\Misc\ConversationActionButtons::getActionsByLocation($actions, \App\Misc\ConversationActionButtons::LOCATION_TOOLBAR);
                    $dropdown_actions = \App\Misc\ConversationActionButtons::getActionsByLocation($actions, \App\Misc\ConversationActionButtons::LOCATION_DROPDOWN);
                @endphp
                <div class="conv-actions f-toolbar__group">
                    @foreach ($toolbar_actions as $action_key => $action)
                        @if (!empty($action['url']))
                            <a href="{{ $action['url']($conversation) }}" class="f-button f-button--ghost f-button--icon {{ $action['class'] }} conv-action @if (!empty($action['mobile_only'])) hidden-xs @endif"
                                @if (!empty($action['attrs']))
                                    @foreach ($action['attrs'] as $attr_key => $attr_value)
                                        {{ $attr_key }}="{{ $attr_value }}"
                                    @endforeach
                                @endif
                                title="{{ $action['label'] }}" aria-label="{{ $action['label'] }}">@include('conversations/partials/action_icon', ['icon' => $action['icon']])</a>
                        @else
                            <button type="button" class="f-button f-button--ghost f-button--icon {{ $action['class'] }} conv-action @if ($action_key === 'delete' || !empty($action['mobile_only'])) hidden-xs @endif"
                                @if (!empty($action['attrs']))
                                    @foreach ($action['attrs'] as $attr_key => $attr_value)
                                        {{ $attr_key }}="{{ $attr_value }}"
                                    @endforeach
                                @endif
                                title="{{ $action['label'] }}" aria-label="{{ $action['label'] }}">@include('conversations/partials/action_icon', ['icon' => $action['icon']])</button>
                        @endif
                    @endforeach

                    @if (App\Ai\Drafts::allowed(Auth::user(), $conversation))
                        <button type="button" class="f-button f-button--ghost f-button--icon conv-action ai-draft-action" title="{{ __('Draft with AI') }}" aria-label="{{ __('Draft with AI') }}"><i class="glyphicon glyphicon-ai" aria-hidden="true"></i></button>
                    @endif

                    @action('conversation.action_buttons', $conversation, $mailbox)

                    <x-fruit::menu :title="__('More Actions')" class="conv-action conv-more-actions">
                        <x-slot:trigger class="f-button--ghost f-button--icon" :aria-label="__('More Actions')" :title="__('More Actions')"><x-heroicon-o-ellipsis-horizontal class="f-icon" aria-hidden="true" /></x-slot:trigger>
                        <ul class="menu-module-items">@action('conversation.prepend_action_buttons', $conversation, $mailbox)</ul>
                        @foreach ($dropdown_actions as $action_key => $action)
                            @if ($action_key === 'delete_mobile')
                                <x-fruit::menu-link href="#" :class="$action['class'].' hidden-lg hidden-md hidden-sm'">@include('conversations/partials/action_icon', ['icon' => $action['icon']]) {{ $action['label'] }}</x-fruit::menu-link>
                            @elseif (!empty($action['has_opposite']))
                                <x-fruit::menu-link href="#" :class="$action['class'].($is_following ? ' hidden' : '')" data-follow-action="follow">@include('conversations/partials/action_icon', ['icon' => $action['icon']]) {{ $action['label'] }}</x-fruit::menu-link>
                                <x-fruit::menu-link href="#" :class="$action['opposite']['class'].(!$is_following ? ' hidden' : '')" data-follow-action="unfollow">@include('conversations/partials/action_icon', ['icon' => $action['icon']]) {{ $action['opposite']['label'] }}</x-fruit::menu-link>
                            @else
                                <a role="menuitem" href="{{ !empty($action['url']) ? $action['url']($conversation) : '#' }}" class="f-menu-item {{ $action['class'] }}"
                                    @if (!empty($action['attrs']))
                                        @foreach ($action['attrs'] as $attr_key => $attr_value)
                                            {{ $attr_key }}="{{ $attr_value }}"
                                        @endforeach
                                    @endif
                                    >@include('conversations/partials/action_icon', ['icon' => $action['icon']]) {{ $action['label'] }}</a>
                            @endif
                        @endforeach
                        <ul class="menu-module-items">@action('conversation.append_action_buttons', $conversation, $mailbox)</ul>
                    </x-fruit::menu>
                </div>

                <span class="f-toolbar__spacer"></span>

                <ul class="conv-info">
                    @action('conversation.convinfo.prepend', $conversation, $mailbox)
                    @if ($conversation->state != App\Conversation::STATE_DELETED)
                        <li>
                            <x-fruit::menu :title="__('Assignee')" id="conv-assignee" class="conv-user">
                                <x-slot:trigger class="f-button--small" :title="__('Assignee').': '.$conversation->getAssigneeName(true)"><x-heroicon-o-user class="f-icon" aria-hidden="true" /> <span class="conv-info-val"><span>{{ $conversation->getAssigneeName(true) }}</span></span></x-slot:trigger>
                                <x-fruit::menu-link href="#" data-user_id="-1" :class="!$conversation->user_id ? 'active' : ''" :aria-current="!$conversation->user_id ? 'true' : null">{{ __("Anyone") }}</x-fruit::menu-link>
                                <x-fruit::menu-link href="#" :data-user_id="Auth::user()->id" :class="$conversation->user_id == Auth::user()->id ? 'active' : ''" :aria-current="$conversation->user_id == Auth::user()->id ? 'true' : null">{{ __("Me") }}</x-fruit::menu-link>
                                @foreach ($mailbox->usersAssignable() as $assignable_user)
                                    @if ($assignable_user->id != Auth::user()->id)
                                        @php
                                            $a_class = \Eventy::filter('assignee_list.a_class', '', $assignable_user);
                                        @endphp
                                        <x-fruit::menu-link href="#" :data-user_id="$assignable_user->id" :class="trim($a_class.($conversation->user_id == $assignable_user->id ? ' active' : ''))" :aria-current="$conversation->user_id == $assignable_user->id ? 'true' : null">{{ $assignable_user->getFullName() }}@action('assignee_list.item_append', $assignable_user)</x-fruit::menu-link>
                                    @endif
                                @endforeach
                            </x-fruit::menu>
                        </li>
                    @endif
                    <li>
                        @php
                            $status_tones = ['success' => 'success', 'info' => 'accent', 'warning' => 'warning', 'danger' => 'danger'];
                        @endphp
                        <x-fruit::menu :title="__('Status')" id="conv-status" class="conv-status">
                            @if ($conversation->state != App\Conversation::STATE_DELETED)
                                <x-slot:trigger class="f-button--small" :title="__('Status').': '.$conversation->getStatusName()"><span class="f-badge f-badge--{{ $status_tones[$conversation->getStatusClass()] ?? 'neutral' }} conv-status-dot" aria-hidden="true"></span> <span class="conv-info-val"><span>{{ $conversation->getStatusName() }}</span></span></x-slot:trigger>
                                @if (!$conversation->isSpam())
                                    @foreach (App\Conversation::$statuses as $status => $dummy)
                                        <x-fruit::menu-link href="#" :data-status="$status" :class="$conversation->status == $status ? 'active' : ''" :aria-current="$conversation->status == $status ? 'true' : null">{{ App\Conversation::statusCodeToName($status) }}</x-fruit::menu-link>
                                    @endforeach
                                @else
                                    <x-fruit::menu-link href="#" data-status="not_spam">{{ __('Not Spam') }}</x-fruit::menu-link>
                                @endif
                            @else
                                <x-slot:trigger class="f-button--small"><x-heroicon-o-trash class="f-icon" aria-hidden="true" /> <span class="conv-info-val"><span>{{ __('Deleted') }}</span></span></x-slot:trigger>
                                <x-fruit::menu-link href="#" class="conv-restore-trigger">{{ __('Restore') }}</x-fruit::menu-link>
                            @endif
                        </x-fruit::menu>
                    </li>@action('conversation.convinfo.before_nav', $conversation, $mailbox)<li class="conv-next-prev">
                        <a href="{{ $conversation->urlPrev(App\Conversation::getFolderParam()) }}" class="f-button f-button--ghost f-button--icon" title="{{ __("Newer") }}" aria-label="{{ __("Newer") }}"><x-heroicon-o-chevron-up class="f-icon" aria-hidden="true" /></a>
                        <a href="{{ $conversation->urlNext(App\Conversation::getFolderParam()) }}" class="f-button f-button--ghost f-button--icon" title="{{ __("Older") }}" aria-label="{{ __("Older") }}"><x-heroicon-o-chevron-down class="f-icon" aria-hidden="true" /></a>
                    </li><li class="conv-customer-toggle">
                        <button type="button" class="f-button f-button--ghost f-button--icon app-inspector-toggle" x-data x-on:click="let ws = $el.closest('.app-workspace'); ws.dataset.view = ws.dataset.view === 'inspector' ? '' : 'inspector'; $el.setAttribute('aria-expanded', ws.dataset.view === 'inspector')" aria-expanded="false" aria-controls="app-inspector" aria-label="{{ __('Customer') }}" title="{{ __('Customer') }}"><x-heroicon-o-user-circle class="f-icon" aria-hidden="true" /></button>
                    </li>
                </ul>
    </div>
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

    <div id="conv-layout" class="conv-type-{{ strtolower($conversation->getTypeName()) }} @if ($is_following) conv-following @endif">
        <div id="conv-layout-header">
            <div id="conv-subject">
                <header class="conv-heading">
                    @php $conv_starred = $conversation->isStarredByUser(); @endphp
                    <div class="conv-heading__overline">
                        <span>{{ $conversation->getStatusName() }} · #{{ $conversation->number }}</span>
                        <span class="conv-heading__tools">
                            <span id="conv-viewers">
                                @foreach ($viewers as $viewer)
                                    <span class="viewer-{{ $viewer['user']->id }} @if ($viewer['replying']) viewer-replying @endif" title="@if ($viewer['replying']){{ __(':user is replying', ['user' => $viewer['user']->getFullName()]) }}@else{{ __(':user is viewing', ['user' => $viewer['user']->getFullName()]) }}@endif">
                                        @include('partials/person_photo', ['person' => $viewer['user']])
                                    </span>
                                @endforeach
                            </span>
                            <button type="button" class="f-button f-button--ghost f-button--icon f-button--small conv-star" aria-pressed="{{ $conv_starred ? 'true' : 'false' }}" aria-label="{{ __('Star Conversation') }}" title="@if ($conv_starred){{ __("Unstar Conversation") }}@else{{ __("Star Conversation") }}@endif"><x-heroicon-o-star class="f-icon conv-star__off" aria-hidden="true" /><x-heroicon-s-star class="f-icon conv-star__on" aria-hidden="true" /></button>
                        </span>
                    </div>
                    <div class="conv-subjtext">
                        <h2>{{ $conversation->getSubject() }}</h2>
                        <div class="f-input-group conv-subj-editor">
                            <input type="text" id="conv-subj-value" class="f-input" value="{{ $conversation->getSubject() }}" aria-label="{{ __('Subject') }}" />
                            <button class="f-button f-button--primary" type="button" data-loading-text="…" aria-label="{{ __('Save') }}"><x-heroicon-o-check class="f-icon" aria-hidden="true" /></button>
                        </div>
                    </div>
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
                            <button type="button" class="f-button f-button--small f-button--primary chat-accept" data-loading-text="{{ __('Accept Chat') }}…">{{ __('Accept Chat') }}</button>
                        @elseif (!$conversation->isClosed())
                            <button type="button" class="f-button f-button--small chat-end" data-loading-text="{{ __('End Chat') }}…">{{ __('End Chat') }}</button>
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

        <div id="conv-layout-main" class="conv-thread">
            @include('conversations/partials/ai_summary')
            @action('conversation.before_threads', $conversation)
            @include('conversations/partials/threads')
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
