<div class="thread-editor-container @if ($thread->type == \App\Thread::TYPE_NOTE) conv-note-block @endif">
    <textarea class="f-input thread-editor" rows="8">{!! htmlspecialchars($thread->body) !!}</textarea>

    <div class="thread-editor-statusbar">
        <a href="#" class="f-button f-button--ghost thread-editor-cancel">{{ __('Cancel') }}</a> 
        <button type="submit" class="f-button f-button--primary thread-editor-save" data-loading-text="{{ __('Saving') }}…">
            {{ __('Save') }}
        </button>
    </div>
   
</div>