@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

<div id="editor_signature" class="conv-composer__signature f-prose">
    @if ($mailbox->signature)
        {!! safe_raw_html($conversation->getSignatureProcessed([], true)) !!}
    @endif
</div>
<div id="editor_bottom_toolbar" class="f-composer__footer conv-composer__footer">
    @action('conv_editor.editor_toolbar_prepend', $mailbox, $conversation)
    <label class="conv-composer__field"><span class="editor-btm-text">{{ __('Status') }}</span>
    {{-- Note keeps status--}}
	<select name="status" class="f-input parsley-exclude" data-reply-status="@if ($mailbox->ticket_status == App\Mailbox::TICKET_STATUS_KEEP_CURRENT){{ $conversation->status }}@else{{ $mailbox->ticket_status }}@endif" data-note-status="{{ $conversation->status }}">
        @foreach (App\Conversation::getStatusesWithNames([App\Conversation::STATUS_SPAM]) as $status_id => $status_name)
            <option value="{{ $status_id }}" @if ($mailbox->ticket_status == $status_id || ($mailbox->ticket_status == App\Mailbox::TICKET_STATUS_KEEP_CURRENT && $conversation->status == $status_id))selected="selected"@endif>{{ $status_name }}</option>
        @endforeach
    </select></label>
    <small class="note-bottom-div"></small>
    <label class="conv-composer__field"><span class="editor-btm-text">{{ __('Assign to') }}</span>
    {{-- Note never changes Assignee --}}
    <select name="user_id" class="f-input parsley-exclude">
        <option value="-1" @if ($mailbox->ticket_assignee == App\Mailbox::TICKET_ASSIGNEE_ANYONE || ($mailbox->ticket_assignee == App\Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT && $conversation->assignee == App\Mailbox::TICKET_ASSIGNEE_ANYONE))data-default="true" selected="selected"@endif>{{ __('Anyone') }}</option>
    	<option value="{{ Auth::user()->id }}" @if (
            ($conversation->user_id == Auth::user()->id && $mailbox->ticket_assignee != App\Mailbox::TICKET_ASSIGNEE_ANYONE) 
            || (!$conversation->user_id && $mailbox->ticket_assignee == App\Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED) 
            || $mailbox->ticket_assignee == App\Mailbox::TICKET_ASSIGNEE_REPLYING
            || ($mailbox->ticket_assignee == App\Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT && $conversation->user_id == Auth::user()->id))data-default="true" selected="selected"@endif>{{ __('Me') }}</option>
        @foreach ($mailbox->usersAssignable() as $user)
            @if ($user->id != Auth::user()->id)
            	<option value="{{ $user->id }}" @if ($conversation->user_id == $user->id && !in_array($mailbox->ticket_assignee, [App\Mailbox::TICKET_ASSIGNEE_REPLYING, App\Mailbox::TICKET_ASSIGNEE_ANYONE]))data-default="true" selected="selected"@endif @action('assignee_list.option_attrs', $user)>{{ $user->getFullName() }}@action('assignee_list.item_append', $user)</option>
            @endif
        @endforeach
    </select></label>

    <input type="hidden" name="after_send" id="after_send" value="{{ $after_send }}" class="parsley-exclude"/>
    <span id="saved-replies-data" class="hidden"
        data-items="{{ json_encode(App\SavedReply::forEditor($mailbox, Auth::user())) }}"
        data-mailbox_id="{{ $mailbox->id }}"
        data-can-save="{{ (int) App\SavedReply::canManage(Auth::user(), $mailbox) }}"
        data-template="{{ (int) (bool) App\SavedReply::template($mailbox->id) }}"
        data-new="{{ (int) !empty($new_converstion) }}"
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
    <span id="attachment-reminder" class="hidden" data-phrases="{{ json_encode(App\Http\Controllers\AttachmentsController::reminderPhrases()) }}" data-message="{{ __('You mentioned :phrase but there is no attachment. Send anyway?') }}" data-send="{{ __('Send Anyway') }}"></span>
    <span id="noreply-patterns" class="hidden" data-regexes="{{ json_encode(App\Misc\Noreply::regexes()) }}" data-message="{{ __(':email looks like an address that does not read replies.') }}"></span>
    {{-- One Send button: it sends with the status chosen above (its label says
         which); the menu sends with another status right away. --}}
    <span class="f-toolbar__spacer"></span>
    <div class="f-button-group btn-group-send">
        <button type="button" class="f-button f-button--primary btn-reply-submit" data-loading-text="{{ __('Sending') }}…"><span class="btn-send-text">@if (empty($new_converstion)){{ __('Send Reply') }}@else{{ __('Send') }}@endif</span><span class="btn-send-forward">{{ __('Forward') }}</span><span class="btn-add-note-text">{{ __('Add Note') }}</span><span class="btn-create-conv">{{ __('Create') }}</span></button>
        <x-fruit::menu :title="__('More send options')" class="dropdown-send-status">
            <x-slot:trigger class="f-button--primary f-button--icon" :aria-label="__('More send options')"><span class="f-menu__chevron" aria-hidden="true"></span></x-slot:trigger>
            <ul class="menu-module-items">@action('conversation.prepend_send_dropdown', $conversation, $mailbox, $new_converstion ?? false)</ul>
            @foreach ([
                App\Conversation::STATUS_CLOSED  => [__('Send & Close'), __('Add Note & Close'), __('Forward & Close')],
                App\Conversation::STATUS_ACTIVE  => [__('Send & Active'), __('Add Note & Active'), __('Forward & Active')],
                App\Conversation::STATUS_PENDING => [__('Send & Pending'), __('Add Note & Pending'), __('Forward & Pending')],
            ] as $send_status => [$send_label, $note_label, $forward_label])
                <x-fruit::menu-link href="#" :data-send-status="$send_status" :data-label="$send_label"><span class="send-status-reply">{{ $send_label }}</span><span class="send-status-note">{{ $note_label }}</span><span class="send-status-forward">{{ $forward_label }}</span></x-fruit::menu-link>
            @endforeach
            <ul class="menu-module-items">@action('conversation.append_send_dropdown', $conversation, $mailbox, $new_converstion ?? false)</ul>
        </x-fruit::menu>
    </div>
</div>
