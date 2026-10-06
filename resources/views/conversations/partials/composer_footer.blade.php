{{-- The composers' footer (livewire/conversation-composer, livewire/new-conversation): status,
     assignee, the editor menus' data and the send button: $send_label, $send_menu (status => label),
     $history (the Conversation History choice; $history_exclude, $conv_history), $is_new;
     $send_only (the chat view): just the send button, status and assignee are the toolbar's. --}}
{{-- The chat view ($send_only): Send is in the chat field (composer_editor); the footer only carries the editor menus' data. --}}
<div id="editor_bottom_toolbar" class="f-composer__footer conv-composer__footer" @if (!empty($send_only)) hidden @endif>
    @action('conv_editor.editor_toolbar_prepend', $mailbox, $conversation)
    @if (empty($send_only))
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
    @endif

    <span id="saved-replies-data" class="hidden"
        data-items="{{ json_encode(App\SavedReply::forEditor($mailbox, Auth::user())) }}"
        data-mailbox_id="{{ $mailbox->id }}"
        data-can-save="{{ (int) App\SavedReply::canManage(Auth::user(), $mailbox) }}"
        data-template="{{ (int) (bool) App\SavedReply::template($mailbox->id) }}"
        data-new="{{ (int) !empty($is_new) }}"
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
    @if (empty($send_only))
        <div class="f-button-group btn-group-send">
            <button type="submit" class="f-button f-button--primary btn-reply-submit" wire:loading.attr="aria-busy" wire:target="send">{{ $send_label }}</button>
            <x-fruit::menu :title="__('More Send Options')" class="dropdown-send-status">
                <x-slot:trigger class="f-button--primary f-button--icon" :aria-label="__('More Send Options')"><span class="f-menu__chevron" aria-hidden="true"></span></x-slot:trigger>
                <ul class="menu-module-items">@action('conversation.prepend_send_dropdown', $conversation, $mailbox, !empty($is_new))</ul>
                @foreach ($send_menu as $send_status => $send_menu_label)
                    <x-fruit::menu-link href="#" :data-send-status="$send_status" x-on:click.prevent="submit({{ $send_status }})">{{ $send_menu_label }}</x-fruit::menu-link>
                @endforeach
                <ul class="menu-module-items">@action('conversation.append_send_dropdown', $conversation, $mailbox, !empty($is_new))</ul>
                @if (!empty($history))
                    {{-- How much of the conversation the email quotes. --}}
                    <x-fruit::menu-separator />
                    <x-fruit::menu-group :label="__('Conversation History')" class="conv-history">
                        @foreach (App\Conversation::$email_history_codes as $history_code)
                            @if (!in_array($history_code, $history_exclude ?? []))
                                <x-fruit::menu-radio :checked="($conv_history ?: 'global') == $history_code" wire:click="$set('conv_history', '{{ $history_code }}')">{{ App\Conversation::getEmailHistoryName($history_code) }}</x-fruit::menu-radio>
                            @endif
                        @endforeach
                    </x-fruit::menu-group>
                @endif
            </x-fruit::menu>
        </div>
    @endif
</div>
