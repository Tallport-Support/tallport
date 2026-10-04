{{-- A new conversation (App\Livewire\NewConversation; tallportComposer in public/js/conversations.js). --}}
@php
    $is_phone = $type == App\Conversation::TYPE_PHONE;
    $thread = $thread_id ? App\Thread::find($thread_id) : null;
    if (!$thread) {
        $thread = new App\Thread();
    }
    // A phone conversation is noted down: its menu says Add Note.
    $send_labels = [
        App\Conversation::STATUS_CLOSED  => $is_phone ? __('Add Note & Close') : __('Send & Close'),
        App\Conversation::STATUS_ACTIVE  => $is_phone ? __('Add Note & Active') : __('Send & Active'),
        App\Conversation::STATUS_PENDING => $is_phone ? __('Add Note & Pending') : __('Send & Pending'),
    ];
@endphp
<div id="conv-layout" class="conv-new" x-data="tallportComposer({{ (int) $conversation_id }})" x-on:input="changed($event)" x-on:change="blurred($event)" x-on:fruit-editor-upload.stop="embed($event)" x-on:keydown.enter="enter($event)">
    <div id="conv-layout-header">
        <div id="conv-toolbar" class="f-toolbar conv-header-bar conv-new-header">
            <h1 class="f-title-2">{{ __("New Conversation") }}</h1>
            <x-fruit::segmented :legend="__('Type')" class="conv-switch">
                <x-fruit::segment name="conv_type" id="email-conv-switch" :value="App\Conversation::TYPE_EMAIL" wire:model.live="type">{{ __('Email') }}</x-fruit::segment>
                <x-fruit::segment name="conv_type" id="phone-conv-switch" :value="App\Conversation::TYPE_PHONE" wire:model.live="type">{{ __('Phone') }}</x-fruit::segment>
            </x-fruit::segmented>
            @action('conversation.new.conv_switch_buttons')
            <span class="f-toolbar__spacer"></span>
            <span class="conv-info f-muted">#<strong class="conv-new-number">@if ($number){{ $number }}@else{{ __("Pending") }}@endif</strong></span>
        </div>
    </div>
    <div id="conv-layout-customer">
        @if ($customer)
            @include('conversations/partials/customer_sidebar')
        @endif
        @action('conversation.new.customer_sidebar', $conversation, $mailbox)
    </div>
    <div id="conv-layout-main" class="conv-new-form">
        <div class="conv-block @if ($is_phone) conv-note-block conv-phone-block @endif">
            <x-fruit::composer class="form-reply conv-composer" id="form-create" :aria-label="__('New Conversation')" x-on:submit.prevent="submit()">
                <div class="f-stack conv-composer__recipients">
                    @if ($conversation->created_by_user_id && $conversation->created_by_user)
                        <p class="f-muted">{{ __('Author') }}: {{ $conversation->created_by_user->getFullName() }}</p>
                    @endif

                    @if ($is_phone)
                        <x-fruit::field :label="__('Customer Name')" control-id="name" class="conv-new-phone-field">
                            <x-fruit::input id="name" wire:model.live.debounce.250ms="name_query" autocomplete="off" required />
                        </x-fruit::field>
                        @if (count($this->nameMatches))
                            <ul class="f-item-list conv-new-customers">
                                @foreach ($this->nameMatches as $match_id => $match_label)
                                    <li wire:key="customer-{{ $match_id }}"><button type="button" class="f-item-row" wire:click="chooseCustomer({{ (int) $match_id }})"><span class="f-item-row__title">{{ $match_label }}</span></button></li>
                                @endforeach
                            </ul>
                        @endif
                        <x-fruit::field :label="__('Phone')" control-id="phone" class="conv-new-phone-field">
                            <x-fruit::input type="tel" id="phone" wire:model="phone" :placeholder="__('(optional)')" />
                        </x-fruit::field>
                        @if ($show_email)
                            <x-fruit::field :label="__('Email')" control-id="to_email" class="conv-new-phone-field">
                                <x-fruit::input type="email" id="to_email" wire:model.live.debounce.500ms="to_email" :placeholder="__('(optional)')" />
                            </x-fruit::field>
                        @else
                            <div><button type="button" class="f-button f-button--ghost f-button--small" id="toggle-email" wire:click="$set('show_email', true)">{{ __('Add Email') }}</button></div>
                        @endif
                    @else
                        @if (count($from_aliases))
                            <x-fruit::field :label="__('From')" class="conv-from-alias">
                                <x-fruit::select name="from_alias" wire:model="from_alias">
                                    @foreach ($from_aliases as $from_alias_email => $from_alias_name)
                                        <option value="@if ($from_alias_email != $mailbox->email){{ $from_alias_email }}@endif">@if ($from_alias_name){{ $from_alias_email }} ({{ $from_alias_name }})@else{{ $from_alias_email }}@endif</option>
                                    @endforeach
                                </x-fruit::select>
                            </x-fruit::field>
                        @endif
                        <x-fruit::field :label="__('To')" class="conv-recipient" id="field-to">
                            <x-fruit::token-field name="to" id="to" wire:model.live="to" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $to }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                        </x-fruit::field>
                        @if (count($recipients) > 1)
                            <x-fruit::checkbox name="multiple_conversations" id="multiple_conversations" wire:model="multiple_conversations">{{ __('Send emails separately to each recipient') }}</x-fruit::checkbox>
                        @endif
                        @if ($show_cc)
                            <x-fruit::field :label="__('Cc')" class="conv-recipient field-cc">
                                <x-fruit::token-field name="cc" id="cc" wire:model.live="cc" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $cc }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                            </x-fruit::field>
                            <x-fruit::field :label="__('Bcc')" class="conv-recipient field-cc">
                                <x-fruit::token-field name="bcc" id="bcc" wire:model.live="bcc" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $bcc }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                            </x-fruit::field>
                        @else
                            <div class="cc-toggler"><button type="button" class="f-button f-button--ghost f-button--small" id="toggle-cc" wire:click="$set('show_cc', true)">{{ __('Cc') }}/{{ __('Bcc') }}</button></div>
                        @endif
                        @foreach ($noreply as $noreply_email)
                            <x-fruit::alert tone="warning" class="noreply-alert">{!! __safe_raw_html(':email looks like an address that does not read replies.', ['email' => '<strong>'.e($noreply_email).'</strong>']) !!}</x-fruit::alert>
                        @endforeach
                    @endif

                    @action('conversation.create_form.before_subject', $conversation, $mailbox, $thread)
                    <x-fruit::field :label="__('Subject')" control-id="subject">
                        <x-fruit::input id="subject" name="subject" wire:model="subject" maxlength="998" required />
                    </x-fruit::field>
                    @action('conversation.create_form.subject_append')
                    @action('conversation.create_form.after_subject', $conversation, $mailbox, $thread)
                </div>

                @include('conversations/partials/composer_editor', ['plain' => false, 'placeholder' => null, 'draft_button' => true])

                @if (!$is_phone && $mailbox->signature)
                    <div id="editor_signature" class="conv-composer__signature f-prose">{!! safe_raw_html($conversation->getSignatureProcessed([], true)) !!}</div>
                @endif

                @include('conversations/partials/composer_footer', [
                    'send_label' => $is_phone ? __('Create') : __('Send'),
                    'send_menu'  => $send_labels,
                    'history'    => false,
                    'is_new'     => true,
                ])
            </x-fruit::composer>
        </div>
    </div>
    <span id="attachment-reminder" class="hidden" data-phrases="{{ json_encode(App\Http\Controllers\AttachmentsController::reminderPhrases()) }}" data-message="{{ __('You mentioned :phrase but there is no attachment. Send anyway?') }}" data-send="{{ __('Send Anyway') }}"></span>
</div>
