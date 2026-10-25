@extends('mailboxes/layout_page')

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

{{-- A mailbox's Email (a channel like the others), its Address tab: the address and aliases, and what its
     emails to customers say, saved with its button (they go to customers); what it can't do yet above. --}}
@section('content')
    @include('mailboxes/email_menu')

    @include('partials/flash_messages')
    <form id="mailbox-email-form" class="settings-form" method="POST" action="{{ route('mailboxes.email.save', ['id' => $mailbox->id]) }}" x-data="tallportMailboxUpdate(@js((string) App\Mailbox::FROM_NAME_CUSTOM))">
        {{ csrf_field() }}

        <x-fruit::form-section :title="__('Address')" :level="3">
            @if (Auth::user()->isAdmin())
                <x-fruit::field :label="__('Email Address')" :description="__('Without one, this mailbox doesn’t send or receive email.')" layout="row">
                    <x-fruit::input type="email" id="email" name="email" :value="old('email', $mailbox->email)" maxlength="128" />
                </x-fruit::field>
            @else
                <div class="f-form-row">
                    <span class="f-label">{{ __('Email Address') }}</span>
                    <span>{{ $mailbox->hasEmail() ? $mailbox->email : __('No email address') }}</span>
                </div>
            @endif
            @if (Auth::user()->can('updateSettings', $mailbox))
                <x-fruit::field :label="__('Aliases')" :description="__('Aliases are other email addresses that also forward to your mailbox address. Separate each email with a comma.')" layout="row">
                    <x-fruit::input id="aliases" name="aliases" :value="old('aliases', $mailbox->aliases)" :placeholder="'alias1@example.org, alias2@example.org('.__('Mailbox Name').')'" />
                </x-fruit::field>
                <x-fruit::checkbox id="aliases_reply" name="aliases_reply" value="1" :checked="(bool) old('aliases_reply', $mailbox->aliases_reply)">{{ __('Allow to reply from aliases') }}</x-fruit::checkbox>
            @endif
        </x-fruit::form-section>

        @if (Auth::user()->can('updateSettings', $mailbox) || Auth::user()->can('updateEmailSignature', $mailbox))
            <x-fruit::form-section :title="__('Emails to Customers')" :level="3">
                @if (Auth::user()->can('updateSettings', $mailbox))
                    <x-fruit::field :label="__('From Name')" :description="strip_tags(__('Name that will appear in the <strong>From</strong> field when a customer views your email.'))" layout="row">
                        <x-fruit::select id="from_name" name="from_name" required x-on:change="fromNameChanged">
                            <option value="{{ App\Mailbox::FROM_NAME_MAILBOX }}" @selected(old('from_name', $mailbox->from_name) == App\Mailbox::FROM_NAME_MAILBOX)>{{ __('Mailbox Name') }}</option>
                            <option value="{{ App\Mailbox::FROM_NAME_USER }}" @selected(old('from_name', $mailbox->from_name) == App\Mailbox::FROM_NAME_USER)>{{ __("User's Name") }}</option>
                            <option value="{{ App\Mailbox::FROM_NAME_CUSTOM }}" @selected(old('from_name', $mailbox->from_name) == App\Mailbox::FROM_NAME_CUSTOM)>{{ __('Custom Name') }}</option>
                        </x-fruit::select>
                    </x-fruit::field>

                    <x-fruit::field :label="__('Custom From Name')" id="from_name_custom_container" :hidden="old('from_name', $mailbox->from_name) != App\Mailbox::FROM_NAME_CUSTOM" layout="row">
                        <x-fruit::input id="from_name_custom" name="from_name_custom" :value="old('from_name_custom', $mailbox->from_name_custom)" maxlength="128" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Auto Bcc')" :description="__('Send a copy of all outgoing replies to specific external addresses.').' '.__('Separate each email with a comma.')" layout="row">
                        <x-fruit::input id="auto_bcc" name="auto_bcc" :value="old('auto_bcc', $mailbox->auto_bcc)" maxlength="255" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Email Header')" :description="__('This text will be added to the beginning of each email reply sent to a customer.')" control-id="before_reply" layout="row">
                        <div class="f-input-group">
                            <span class="f-input-group__addon"><input type="checkbox" class="f-check" @if ($mailbox->before_reply) checked="checked" @endif id="before-reply-toggle" x-on:change="beforeReplyToggled" aria-label="{{ __('Email Header') }}"></span>
                            <input id="before_reply" type="text" class="f-input" @if (!$mailbox->before_reply) readonly @endif name="before_reply" value="{{ old('before_reply', $mailbox->before_reply) }}" data-default="-- {{ __('Please reply above this line') }} --" placeholder="-- {{ __('Please reply above this line') }} --" aria-describedby="before_reply-description">
                        </div>
                    </x-fruit::field>
                @endif

                <x-fruit::field :label="__('Email Signature')" class="signature-editor">
                    @php $signature_markdown = App\Misc\Markdown::isMarkdown($mailbox->signature_markdown, $mailbox->signature); @endphp
                    <x-editor id="signature" name="signature" rows="8" vars :markdown="$signature_markdown" switchable>{{ $signature_markdown ? old('signature_markdown', $mailbox->signature_markdown) : old('signature', $mailbox->signature) }}</x-editor>
                </x-fruit::field>
            </x-fruit::form-section>
        @endif

        @action('mailbox.update.after_signature', $mailbox)

        <footer class="f-form-row settings-form__actions">
            <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
        </footer>
    </form>

    {{-- Its auto reply: the mailbox's, or its own. --}}
    @if ($mailbox->hasEmail())
        @include('mailboxes/partials/message_links', ['key' => 'email'])
    @endif
@endsection
