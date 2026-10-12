{{-- A message edited in place, in the thread list (App\Livewire\ConversationThread). --}}
<div class="thread-editor-container @if ($thread->type == \App\Thread::TYPE_NOTE) conv-note-block @endif" data-thread_id="{{ $thread->id }}">
    <x-editor class="thread-editor" :id="'thread-editor-'.$thread->id" rows="8" :aria-label="__('Message')" :upload-url="route('conversations.upload')" :markdown="$thread->body_markdown !== null">{{ $thread->body_markdown ?? \Helper::stripDangerousTags($thread->body) }}</x-editor>

    <div class="thread-editor-statusbar">
        <button type="button" class="f-button f-button--ghost thread-editor-cancel" wire:click="$set('editing', null)">{{ __('Cancel') }}</button>
        <button type="button" class="f-button f-button--primary thread-editor-save" x-data x-on:click="Tallport.busy($el, true); $wire.saveEdit({{ $thread->id }}, document.getElementById('thread-editor-{{ $thread->id }}').value).then(() => Tallport.busy($el, false))">
            {{ __('Save') }}
        </button>
    </div>
</div>
