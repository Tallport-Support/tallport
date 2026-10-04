@extends('layouts.app')

@section('title_full', __('Edit Mailbox').' - '.$mailbox->name)

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form class="settings-form" method="POST" action="" enctype="multipart/form-data">
            {{ csrf_field() }}

            @action('mailbox.update.before_name', $mailbox, $errors)

            <x-fruit::form-section :title="__('Mailbox')">
                @if (Auth::user()->isAdmin())
                    <x-fruit::field :label="__('Mailbox Name')" layout="row">
                        <x-fruit::input id="name" name="name" :value="old('name', $mailbox->name)" maxlength="40" required autofocus />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Email Address')" layout="row">
                        <x-fruit::input type="email" id="email" name="email" :value="old('email', $mailbox->email)" maxlength="128" required />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Archived')" layout="row">
                        <x-fruit::switch id="mailbox_state" name="state" :value="App\Mailbox::STATE_ARCHIVED" :checked="old('state', $mailbox->state) == App\Mailbox::STATE_ARCHIVED" />
                    </x-fruit::field>
                @else
                    <div class="f-form-row">
                        <span class="f-label">{{ __('Mailbox Name') }}</span>
                        <span>{{ old('name', $mailbox->name) }}</span>
                    </div>
                    <div class="f-form-row">
                        <span class="f-label">{{ __('Email Address') }}</span>
                        <span>{{ old('email', $mailbox->email) }}</span>
                    </div>
                @endif

                @if (Auth::user()->can('updateSettings', $mailbox))
                    <x-fruit::field :label="__('Aliases')" :description="__('Aliases are other email addresses that also forward to your mailbox address. Separate each email with a comma.')" layout="row">
                        <x-fruit::input id="aliases" name="aliases" :value="old('aliases', $mailbox->aliases)" :placeholder="'alias1@example.org, alias2@example.org('.__('Mailbox Name').')'" />
                    </x-fruit::field>
                    <x-fruit::checkbox id="aliases_reply" name="aliases_reply" value="1" :checked="(bool) old('aliases_reply', $mailbox->aliases_reply)">{{ __('Allow to reply from aliases') }}</x-fruit::checkbox>
                @endif
            </x-fruit::form-section>

            @if (Auth::user()->can('updateSettings', $mailbox))
                <x-fruit::form-section :title="__('Conversations')">
                    <x-fruit::field :label="__('Status After Replying')" layout="row">
                        <x-fruit::select id="ticket_status" name="ticket_status" required>
                            <option value="{{ App\Mailbox::TICKET_STATUS_KEEP_CURRENT }}" @selected(old('ticket_status', $mailbox->ticket_status) == App\Mailbox::TICKET_STATUS_KEEP_CURRENT)>{{ __('Keep Current') }}</option>
                            @foreach (App\Conversation::getStatusesWithNames([App\Conversation::STATUS_SPAM]) as $status_id => $status_name)
                                <option value="{{ $status_id }}" @selected(old('ticket_status', $mailbox->ticket_status) == $status_id)>{{ $status_name }}</option>
                            @endforeach
                        </x-fruit::select>
                    </x-fruit::field>

                    @action('mailbox.update.after_ticket_status', $mailbox)

                    <x-fruit::field :label="__('Default Assignee')" layout="row">
                        <x-fruit::select id="ticket_assignee" name="ticket_assignee" required>
                            <option value="{{ App\Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT }}" @selected(old('ticket_assignee', $mailbox->ticket_assignee) == App\Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT)>{{ __('Keep Current') }}</option>
                            <option value="{{ App\Mailbox::TICKET_ASSIGNEE_ANYONE }}" @selected(old('ticket_assignee', $mailbox->ticket_assignee) == App\Mailbox::TICKET_ASSIGNEE_ANYONE)>{{ __('Anyone') }}</option>
                            <option value="{{ App\Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED }}" @selected(old('ticket_assignee', $mailbox->ticket_assignee) == App\Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED)>{{ __('Person Replying (if Unassigned)') }}</option>
                            <option value="{{ App\Mailbox::TICKET_ASSIGNEE_REPLYING }}" @selected(old('ticket_assignee', $mailbox->ticket_assignee) == App\Mailbox::TICKET_ASSIGNEE_REPLYING)>{{ __('Person Replying') }}</option>
                        </x-fruit::select>
                    </x-fruit::field>

                    <x-fruit::checkbox id="chat_start_new" name="chat_start_new" value="1" :checked="(bool) old('chat_start_new', $mailbox->getMeta('chat_start_new'))">{{ __('Start a new conversation when receiving a reply to the closed / deleted Chat conversation') }}</x-fruit::checkbox>
                </x-fruit::form-section>
            @endif

            @if (Auth::user()->can('updateSettings', $mailbox) || Auth::user()->can('updateEmailSignature', $mailbox))
                <x-fruit::form-section :title="__('Emails to Customers')">
                    @if (Auth::user()->can('updateSettings', $mailbox))
                        <x-fruit::field :label="__('From Name')" :description="strip_tags(__('Name that will appear in the <strong>From</strong> field when a customer views your email.'))" layout="row">
                            <x-fruit::select id="from_name" name="from_name" required>
                                <option value="{{ App\Mailbox::FROM_NAME_MAILBOX }}" @selected(old('from_name', $mailbox->from_name) == App\Mailbox::FROM_NAME_MAILBOX)>{{ __('Mailbox Name') }}</option>
                                <option value="{{ App\Mailbox::FROM_NAME_USER }}" @selected(old('from_name', $mailbox->from_name) == App\Mailbox::FROM_NAME_USER)>{{ __("User's Name") }}</option>
                                <option value="{{ App\Mailbox::FROM_NAME_CUSTOM }}" @selected(old('from_name', $mailbox->from_name) == App\Mailbox::FROM_NAME_CUSTOM)>{{ __('Custom Name') }}</option>
                            </x-fruit::select>
                        </x-fruit::field>

                        <x-fruit::field :label="__('Custom From Name')" id="from_name_custom_container" :class="old('from_name', $mailbox->from_name) != App\Mailbox::FROM_NAME_CUSTOM ? 'hidden' : ''" layout="row">
                            <x-fruit::input id="from_name_custom" name="from_name_custom" :value="old('from_name_custom', $mailbox->from_name_custom)" maxlength="128" />
                        </x-fruit::field>

                        <x-fruit::field :label="__('Auto Bcc')" :description="__('Send a copy of all outgoing replies to specific external addresses.').' '.__('Separate each email with a comma.')" layout="row">
                            <x-fruit::input id="auto_bcc" name="auto_bcc" :value="old('auto_bcc', $mailbox->auto_bcc)" maxlength="255" />
                        </x-fruit::field>

                        <x-fruit::field :label="__('Email Header')" :description="__('This text will be added to the beginning of each email reply sent to a customer.')" control-id="before_reply" layout="row">
                            <div class="f-input-group">
                                <span class="f-input-group__addon"><input type="checkbox" class="f-check" @if ($mailbox->before_reply) checked="checked" @endif id="before-reply-toggle" aria-label="{{ __('Email Header') }}"></span>
                                <input id="before_reply" type="text" class="f-input" @if (!$mailbox->before_reply) readonly @endif name="before_reply" value="{{ old('before_reply', $mailbox->before_reply) }}" data-default="-- {{ __('Please reply above this line') }} --" placeholder="-- {{ __('Please reply above this line') }} --" aria-describedby="before_reply-description">
                            </div>
                        </x-fruit::field>
                    @endif

                    <x-fruit::field :label="__('Email Signature')" class="signature-editor">
                        <x-editor id="signature" name="signature" rows="8" vars>{{ old('signature', $mailbox->signature) }}</x-editor>
                    </x-fruit::field>
                </x-fruit::form-section>
            @endif

            @action('mailbox.update.after_signature', $mailbox)

            @if (auth()->user()->isAdmin())
                <x-fruit::form-section :title="__('Danger Zone')">
                    <div class="f-form-row">
                        <div>
                            <strong class="f-headline">{{ __('Delete Mailbox') }}</strong>
                            <p class="f-help">{{ __('Deleting this mailbox will remove all historical data and deactivate related workflows and reports.') }}</p>
                        </div>
                        <a href="#" data-trigger="modal" data-modal-body="#delete_mailbox_modal" data-modal-no-footer="true" data-modal-title="{{ __('Delete the :mailbox_name mailbox?', ['mailbox_name' => $mailbox->name]) }}" data-modal-on-show="deleteMailboxModal" class="f-button f-button--danger">{{ __('Delete mailbox') }}</a>
                    </div>
                </x-fruit::form-section>
            @endif

            <footer class="f-form-row settings-form__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
            </footer>
        </form>
    </div>

    <div id="delete_mailbox_modal" class="hidden">
        <div class="text-large">{{ __('Deleting this mailbox will remove all historical data and deactivate related workflows and reports.') }}</div>

        @if (!Auth::user()->isDummyPassword())
            <div class="text-large margin-top margin-bottom-5">{{ __('Please confirm your password:') }}</div>
            <div class="row">
                <div class="col-xs-7">
                    <input type="password" class="form-control delete-mailbox-pass" />
                </div>
            </div>
        @endif
        <div class="margin-top margin-bottom-5">
            <button class="btn btn-danger button-delete-mailbox" data-loading-text="{{ __('Processing') }}…">{{ __('Delete Mailbox') }}</button>
            <button class="btn btn-link" data-dismiss="modal">{{ __('Cancel') }}</button>
        </div>
    </div>
@endsection


@section('javascript')
    @parent
    mailboxUpdateInit('{{ App\Mailbox::FROM_NAME_CUSTOM }}');
@endsection
