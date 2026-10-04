<div class="thread-editor-container @if ($thread->type == \App\Thread::TYPE_NOTE) conv-note-block @endif">
    <x-editor class="thread-editor" :id="'thread-editor-'.$thread->id" rows="8" :aria-label="__('Message')" :upload-url="route('conversations.upload')">{{ $thread->body }}</x-editor>

    <div class="thread-editor-statusbar">
        <a href="#" class="f-button f-button--ghost thread-editor-cancel">{{ __('Cancel') }}</a> 
        <button type="submit" class="f-button f-button--primary thread-editor-save" data-loading-text="{{ __('Saving') }}…">
            {{ __('Save') }}
        </button>
    </div>
   
</div>