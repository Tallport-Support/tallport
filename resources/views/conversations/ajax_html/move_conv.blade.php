<div class="f-stack modal-form">
    <div class="f-field">
        <label class="f-label" for="move-conv-mailbox-id">{{ __('Select Mailbox') }}</label>
        <select class="f-input move-conv-mailbox-id" id="move-conv-mailbox-id">
            @foreach ($mailboxes as $mailbox)
                @if ($mailbox->id != $conversation->mailbox_id)
                    <option value="{{ $mailbox->id }}">{{ $mailbox->name }} &nbsp;({{ $mailbox->email }})</option>
                @endif
            @endforeach
        </select>
    </div>
    <div class="f-field">
        <label class="f-label" for="move-conv-mailbox-email">{{ __('or Enter Mailbox Email') }}</label>
        <input type="text" class="f-input move-conv-mailbox-email" id="move-conv-mailbox-email" />
    </div>
    <div class="modal-form__actions">
        <button class="f-button f-button--primary btn-move-conv" data-loading-text="{{ __('Moving') }}…" type="submit">{{ __('Move') }}</button>
    </div>
</div>
