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
                    <div class="f-stack conv-composer__recipients">
                        @if (count($from_aliases))
                            <x-fruit::field :label="__('From')" class="conv-from-alias">
                                <x-fruit::select name="from_alias" wire:model="from_alias">
                                    @foreach ($from_aliases as $from_alias_email => $from_alias_name)
                                        <option value="@if ($from_alias_email != $mailbox->email){{ $from_alias_email }}@endif">@if ($from_alias_name){{ $from_alias_email }} ({{ $from_alias_name }})@else{{ $from_alias_email }}@endif</option>
                                    @endforeach
                                </x-fruit::select>
                            </x-fruit::field>
                        @endif

                        @if ($mode == 'forward')
                            <x-fruit::field :label="__('To')" class="conv-recipient conv-recipient-to">
                                <x-fruit::token-field name="to_email" id="to_email" wire:model.live="to_email" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $to_email }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                            </x-fruit::field>
                        @elseif (count($to_customers) > 1)
                            <x-fruit::field :label="__('To')" class="conv-recipient conv-recipient-to">
                                <x-fruit::select name="to" id="to" wire:model.live="to">
                                    @foreach ($to_customers as $to_customer_email => $to_customer_label)
                                        <option value="{{ $to_customer_email }}">{{ $to_customer_label }}</option>
                                    @endforeach
                                </x-fruit::select>
                            </x-fruit::field>
                        @endif

                        @if ($show_cc && !$is_chat)
                            <x-fruit::field :label="__('Cc')" class="conv-recipient field-cc">
                                <x-fruit::token-field name="cc" id="cc" wire:model.live="cc" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $cc }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                            </x-fruit::field>
                            <x-fruit::field :label="__('Bcc')" class="conv-recipient field-cc">
                                <x-fruit::token-field name="bcc" id="bcc" wire:model.live="bcc" :placeholder="__('Email Address')" search="server" x-on:fruit-suggest.debounce.200ms="$wire.set('recipient_query', $event.detail.query)">{{ $bcc }}<x-slot:options>@foreach ($this->recipientMatches as $match_email => $match_label)<option value="{{ $match_email }}">{{ $match_label }}</option>@endforeach</x-slot:options></x-fruit::token-field>
                            </x-fruit::field>
                        @elseif (!$is_chat)
                            <div class="cc-toggler"><button type="button" class="f-button f-button--ghost f-button--small" id="toggle-cc" wire:click="$set('show_cc', true)">{{ __('Cc') }}/{{ __('Bcc') }}</button></div>
                        @endif

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

                @if (collect($attachments)->where('embed', false)->count())
                    <ul class="conv-composer__attachments">
                        @foreach ($attachments as $attachment)
                            @if (empty($attachment['embed']))
                                <li class="attachment-loaded" wire:key="attachment-{{ md5($attachment['id']) }}">
                                    <a href="{{ $attachment['url'] }}" target="_blank">{{ $attachment['name'] }}</a>
                                    <span class="f-muted">({{ \Helper::humanFileSize($attachment['size']) }})</span>
                                    <button type="button" class="f-button f-button--ghost f-button--icon f-button--small" wire:click="removeAttachment(@js($attachment['id']))" aria-label="{{ __('Remove') }}: {{ $attachment['name'] }}" title="{{ __('Remove') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                @endif
                <ul class="conv-composer__uploading" x-show="uploading.length" x-cloak>
                    <template x-for="name in uploading"><li><x-fruit::spinner /> <span x-text="name"></span></li></template>
                </ul>

                <div class="conv-reply-body" wire:ignore>
                    <x-editor id="body" rows="8" :paste="$conversation->isChat() ? 'plain' : 'rich'" :upload-url="route('conversations.upload')" :aria-label="__('Message')" :placeholder="$is_chat ? __('Use ENTER to send the message and SHIFT+ENTER for a new line') : null">
                        {{ $body }}
                        <x-slot:extras>
                            <span class="editor-attach">
                                <button type="button" class="f-button f-button--ghost f-button--icon" aria-label="{{ __('Upload Attachments') }}" title="{{ __('Upload Attachments') }}" x-on:click="$refs.files.click()"><x-heroicon-o-paper-clip class="f-icon" aria-hidden="true" /></button>
                                <input type="file" multiple hidden x-ref="files" x-on:change="upload($el.files); $el.value = ''">
                            </span>
                            @include('conversations/partials/editor_pickers')
                            @if (!$conversation->isChat())
                                <button type="button" class="f-button f-button--ghost f-button--icon" x-data="editorPlainPaste" x-on:click="toggle()" x-bind:aria-pressed="plain ? 'true' : 'false'" aria-pressed="false" aria-label="{{ __('Paste as Plain Text') }}" title="{{ __('Paste as Plain Text') }}"><x-heroicon-o-clipboard-document class="f-icon" aria-hidden="true" /></button>
                            @endif
                            @action('conversation.editor_extras', $conversation, $mailbox)
                            <span class="f-toolbar__spacer"></span>
                            <span class="draft-saved f-footnote f-muted" x-show="saved" x-transition.opacity x-cloak role="status">{{ __('Saved') }}</span>
                            @if ($mode != 'note')
                                <button type="button" class="f-button f-button--ghost f-button--icon note-btn-save-draft" aria-label="{{ __('Save Draft') }}" title="{{ __('Save Draft') }}" x-on:click="save(true)"><x-heroicon-o-check class="f-icon" aria-hidden="true" /></button>
                            @endif
                            <button type="button" class="f-button f-button--ghost f-button--icon note-btn-discard" aria-label="{{ __('Discard') }}" title="{{ __('Discard') }}" x-on:click="discard()"><x-heroicon-o-trash class="f-icon" aria-hidden="true" /></button>
                        </x-slot:extras>
                    </x-editor>
                </div>

                @if ($mode != 'note' && $mailbox->signature && !$is_chat)
                    <div id="editor_signature" class="conv-composer__signature f-prose">{!! safe_raw_html($conversation->getSignatureProcessed([], true)) !!}</div>
                @endif

                <div id="editor_bottom_toolbar" class="f-composer__footer conv-composer__footer">
                    @action('conv_editor.editor_toolbar_prepend', $mailbox, $conversation)
                    <label class="conv-composer__field"><span class="editor-btm-text">{{ __('Status') }}</span>
                        <select name="status" class="f-input" wire:model.live="status">
                            @foreach (App\Conversation::getStatusesWithNames([App\Conversation::STATUS_SPAM]) as $status_id => $status_name)
                                <option value="{{ $status_id }}">{{ $status_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="conv-composer__field"><span class="editor-btm-text">{{ __('Assign to') }}</span>
                        <select name="user_id" class="f-input" wire:model="user_id">
                            <option value="-1">{{ __('Anyone') }}</option>
                            <option value="{{ Auth::user()->id }}">{{ __('Me') }}</option>
                            @foreach ($mailbox->usersAssignable() as $user)
                                @if ($user->id != Auth::user()->id)
                                    <option value="{{ $user->id }}" @action('assignee_list.option_attrs', $user)>{{ $user->getFullName() }}@action('assignee_list.item_append', $user)</option>
                                @endif
                            @endforeach
                        </select>
                    </label>

                    <span id="saved-replies-data" class="hidden"
                        data-items="{{ json_encode(App\SavedReply::forEditor($mailbox, Auth::user())) }}"
                        data-mailbox_id="{{ $mailbox->id }}"
                        data-can-save="{{ (int) App\SavedReply::canManage(Auth::user(), $mailbox) }}"
                        data-template="{{ (int) (bool) App\SavedReply::template($mailbox->id) }}"
                        data-new="0"
                        data-title="{{ __('Saved Replies') }}"
                        data-search="{{ __('Search') }}…"
                        data-empty="{{ __('No saved replies yet.') }}"
                        data-save="{{ __('Save as saved reply') }}"
                        data-name="{{ __('Name') }}"
                        data-save-button="{{ __('Save') }}"></span>
                    <span id="kb-data" class="hidden"
                        data-items="{{ json_encode(App\Http\Controllers\KnowledgeBaseController::forEditor($mailbox->id)) }}"
                        data-title="{{ __('Knowledge Base') }}"
                        data-search="{{ __('Search') }}…"
                        data-empty="{{ __('No articles yet.') }}"></span>

                    <span class="f-toolbar__spacer"></span>
                    <div class="f-button-group btn-group-send">
                        <button type="submit" class="f-button f-button--primary btn-reply-submit" wire:loading.attr="disabled" wire:target="send">@if ($mode == 'note'){{ __('Add Note') }}@elseif ($mode == 'forward'){{ __('Forward') }}@else{{ $send_labels[$status][0] ?? __('Send Reply') }}@endif</button>
                        <x-fruit::menu :title="__('More send options')" class="dropdown-send-status">
                            <x-slot:trigger class="f-button--primary f-button--icon" :aria-label="__('More send options')"><span class="f-menu__chevron" aria-hidden="true"></span></x-slot:trigger>
                            <ul class="menu-module-items">@action('conversation.prepend_send_dropdown', $conversation, $mailbox, false)</ul>
                            @foreach ($send_labels as $send_status => $labels)
                                <x-fruit::menu-link href="#" :data-send-status="$send_status" x-on:click.prevent="submit({{ $send_status }})">{{ $labels[$label_index] }}</x-fruit::menu-link>
                            @endforeach
                            <ul class="menu-module-items">@action('conversation.append_send_dropdown', $conversation, $mailbox, false)</ul>
                            @if (!$conversation->isChat() && $mode != 'note')
                                {{-- How much of the conversation the email quotes. --}}
                                <x-fruit::menu-separator />
                                <x-fruit::menu-group :label="__('Conversation History')" class="conv-history">
                                    @foreach (App\Conversation::$email_history_codes as $history_code)
                                        @if ($mode != 'forward' || !in_array($history_code, ['global', 'none']))
                                            <x-fruit::menu-radio :checked="($conv_history ?: 'global') == $history_code" wire:click="$set('conv_history', '{{ $history_code }}')">{{ App\Conversation::getEmailHistoryName($history_code) }}</x-fruit::menu-radio>
                                        @endif
                                    @endforeach
                                </x-fruit::menu-group>
                            @endif
                        </x-fruit::menu>
                    </div>
                </div>
            </x-fruit::composer>
        </div>
    @endif
    <span id="attachment-reminder" class="hidden" data-phrases="{{ json_encode(App\Http\Controllers\AttachmentsController::reminderPhrases()) }}" data-message="{{ __('You mentioned :phrase but there is no attachment. Send anyway?') }}" data-send="{{ __('Send Anyway') }}"></span>
    @if (App\Ai\Drafts::allowed(Auth::user(), $conversation))
        <div wire:ignore>@include('conversations/partials/ai_draft_panel')</div>
    @endif
    @action('reply_form.after', $conversation)
</div>
