<div class="modal fade" tabindex="-1" role="dialog" id="conv-settings-modal">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">{{ __('Conversation History') }}</h2>
                <button type="button" class="f-button f-button--ghost f-button--icon f-button--small modal-close" data-dismiss="modal" aria-label="{{ __('Close') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button>
            </div>
            <div class="modal-body">
                <form action="">
                    <div class="f-field">
                        <label class="f-label" for="email_history">{{ __('Conversation History') }}</label>

                        <select id="email_history" class="f-input" name="email_history" required autofocus>
                            @foreach(App\Conversation::$email_history_codes as $code)
                                <option value="{{ $code }}">{{ \App\Conversation::getEmailHistoryName($code) }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="f-button f-button--ghost button-cancel-settings">{{ __('Cancel') }}</button>
                <button type="button" class="f-button f-button--primary button-save-settings">{{ __('Save') }}</button>
            </div>
        </div>
    </div>
</div>
