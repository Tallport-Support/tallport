{{-- The conversation's composer (App\Livewire\ConversationComposer; public/js/conversations.js). --}}
@php
    $send_labels = [
        App\Conversation::STATUS_CLOSED  => [__('Send & Close'), __('Add Note & Close'), __('Forward & Close')],
        App\Conversation::STATUS_ACTIVE  => [__('Send & Active'), __('Add Note & Active'), __('Forward & Active')],
        App\Conversation::STATUS_PENDING => [__('Send & Pending'), __('Add Note & Pending'), __('Forward & Pending')],
    ];
    $label_index = $mode == 'note' ? 1 : ($mode == 'forward' ? 2 : 0);
    $is_chat = $conversation->isInChatMode();
@endphp
<div class="conv-action-wrapper" x-data="tallportComposer({{ $conversation->id }}, @js($mode))" x-on:input="changed($event)" x-on:change="blurred($event)" x-on:fruit-editor-upload.stop="embed($event)" x-on:keydown.enter="enter($event)">
    @if ($mode)
        <div class="conv-block conv-reply-block conv-action-block @if ($mode == 'note') conv-note-block @elseif ($mode == 'forward') conv-forward-block @endif">
            <x-fruit::composer placement="top" class="form-reply conv-composer" :aria-label="$mode == 'note' ? __('Note') : ($mode == 'forward' ? __('Forward') : __('Reply'))" x-on:submit.prevent="submit()">
                @if ($mode != 'note')
                    {{-- Mail-style recipient rows (FruitUI inline fields); Cc and Bcc on demand. --}}
                    <div class="conv-composer__recipients" x-data="{ copies: @js($cc !== '' || $bcc !== '') }">
                        @if (count($from_aliases))
                            <x-fruit::field :label="__('From')" layout="inline" class="conv-from-alias">
                                <x-fruit::select name="from_alias" wire:model="from_alias">
                                    @foreach ($from_aliases as $from_alias_email => $from_alias_name)
                                        <option value="@if ($from_alias_email != $mailbox->email){{ $from_alias_email }}@endif">@if ($from_alias_name){{ $from_alias_email }} ({{ $from_alias_name }})@else{{ $from_alias_email }}@endif</option>
                                    @endforeach
                                </x-fruit::select>
                            </x-fruit::field>
                        @endif

                        <x-fruit::field :label="__('To')" layout="inline" class="conv-recipient conv-recipient-to">
                            @if ($mode == 'forward')
                                <x-fruit::token-field name="to_email" id="to_email" wire:model.live="to_email" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $to_email }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                            @elseif (count($to_customers) > 1)
                                <x-fruit::select name="to" id="to" wire:model.live="to">
                                    @foreach ($to_customers as $to_customer_email => $to_customer_label)
                                        <option value="{{ $to_customer_email }}">{{ $to_customer_label }}</option>
                                    @endforeach
                                </x-fruit::select>
                            @else
                                <x-fruit::input id="to" readonly :value="$to_customers[$to] ?? ($conversation->customer ? $conversation->customer->getFullName(true).' <'.$conversation->customer_email.'>' : $conversation->customer_email)" />
                            @endif
                            @unless ($is_chat)
                                <x-fruit::button variant="ghost" size="small" id="toggle-cc" x-show="!copies" aria-controls="cc-row bcc-row" aria-expanded="false" x-on:click="copies = true; $nextTick(() => $root.querySelector('#cc-row input')?.focus())">{{ __('Cc') }}/{{ __('Bcc') }}</x-fruit::button>
                            @endunless
                        </x-fruit::field>

                        @unless ($is_chat)
                            <x-fruit::field :label="__('Cc')" layout="inline" class="conv-recipient field-cc" id="cc-row" x-show="copies" x-cloak>
                                <x-fruit::token-field name="cc" id="cc" wire:model.live="cc" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $cc }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                            </x-fruit::field>
                            <x-fruit::field :label="__('Bcc')" layout="inline" class="conv-recipient field-cc" id="bcc-row" x-show="copies" x-cloak>
                                <x-fruit::token-field name="bcc" id="bcc" wire:model.live="bcc" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $bcc }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                            </x-fruit::field>
                        @endunless

                        @foreach ($noreply as $noreply_email)
                            <x-fruit::alert tone="warning" class="noreply-alert">{!! __safe_raw_html(':email looks like an address that does not read replies.', ['email' => '<strong>'.e($noreply_email).'</strong>']) !!}</x-fruit::alert>
                        @endforeach
                    </div>
                @endif

                @if ($mode == 'reply' && $last_thread && $last_thread->type == App\Thread::TYPE_NOTE && $last_thread->created_by_user_id != Auth::user()->id && $last_thread->created_by_user)
                    <x-fruit::alert tone="warning" class="alert-switch-to-note">
                        {!! __safe_raw_html('This reply will go to the customer. :%switch_start%Switch to a note:%switch_end% if you are replying to :user_name.', ['%switch_start%' => '<a href="#" class="switch-to-note" wire:click.prevent="switchToNote">', '%switch_end%' => '</a>', 'user_name' => htmlspecialchars($last_thread->created_by_user->getFullName())]) !!}
                    </x-fruit::alert>
                @endif

                @include('conversations/partials/composer_editor', ['plain' => $conversation->isChat(), 'placeholder' => $is_chat ? __('Use ENTER to send the message and SHIFT+ENTER for a new line') : null, 'draft_button' => $mode != 'note'])

                @include('conversations/partials/composer_footer', [
                    'send_label'      => $mode == 'note' ? __('Add Note') : ($mode == 'forward' ? __('Forward') : ($send_labels[$status][0] ?? __('Send Reply'))),
                    'send_menu'       => array_map(fn ($labels) => $labels[$label_index], $send_labels),
                    'history'         => !$conversation->isChat() && $mode != 'note',
                    'history_exclude' => $mode == 'forward' ? ['global', 'none'] : [],
                ])
            </x-fruit::composer>
        </div>
    @endif
    <span id="attachment-reminder" class="hidden" data-phrases="{{ json_encode(App\Http\Controllers\AttachmentsController::reminderPhrases()) }}" data-message="{{ __('You mentioned :phrase but there is no attachment. Send anyway?') }}" data-send="{{ __('Send Anyway') }}"></span>
    @if (App\Ai\Drafts::allowed(Auth::user(), $conversation))
        <div wire:ignore>@include('conversations/partials/ai_draft_panel')</div>
    @endif
    @action('reply_form.after', $conversation)
</div>
