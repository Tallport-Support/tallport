{{-- Where the user goes after an action (a FruitUI remote dialog). --}}
<div class="f-stack modal-form" x-data="{ after_send: @js((string) $after_send) }">
    <p>{{ __('This setting gives you control over what page loads after you perform an action (send a reply, add a note, change conversation status or assignee).') }}</p>

    <x-fruit::select name="after_send_default" x-model="after_send" required :aria-label="__('Default Redirect')">
        <option value="{{ App\MailboxUser::AFTER_SEND_STAY }}">{{ __('Stay on the same page') }}</option>
        <option value="{{ App\MailboxUser::AFTER_SEND_NEXT }}">{{ __('Next active conversation') }}</option>
        <option value="{{ App\MailboxUser::AFTER_SEND_FOLDER }}">{{ __('Back to folder') }}</option>
    </x-fruit::select>

    <div class="modal-form__actions">
        <button type="button" class="f-button f-button--ghost" x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</button>
        <button type="button" class="f-button f-button--primary after-send-save" x-on:click="Tallport.busy($el, true); Tallport.post(laroute.route('conversations.ajax'), {action: 'save_after_send', value: after_send, mailbox_id: {{ (int) $mailbox_id }}}).then(r => { Tallport.busy($el, false); if (Tallport.result(r)) { $el.closest('dialog').close(); } })">{{ __('Save') }}</button>
    </div>
</div>
