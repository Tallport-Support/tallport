{{-- The reply editor's own buttons, after FruitUI's formatting toolbar. --}}
<span class="editor-attach" x-data>
    <button type="button" class="f-button f-button--ghost f-button--icon" aria-label="{{ __('Upload Attachments') }}" title="{{ __('Upload Attachments') }}" x-on:click="$refs.files.click()"><x-heroicon-o-paper-clip class="f-icon" aria-hidden="true" /></button>
    <input type="file" multiple hidden x-ref="files" x-on:change="editorAttachFiles($el.files); $el.value = ''">
</span>
@include('conversations/partials/editor_pickers')
@action('conversation.editor_extras', $conversation ?? null, $mailbox)
<span class="f-toolbar__spacer"></span>
<span class="draft-saved"></span>
<button type="button" class="f-button f-button--ghost f-button--icon note-btn-save-draft" aria-label="{{ __('Save Draft') }}" title="{{ __('Save Draft') }}" x-data x-on:click="saveDraft(true)"><x-heroicon-o-check class="f-icon" aria-hidden="true" /></button>
<button type="button" class="f-button f-button--ghost f-button--icon note-btn-discard" aria-label="{{ __('Discard') }}" title="{{ __('Discard') }}" x-data x-on:click="discardDraft()"><x-heroicon-o-trash class="f-icon" aria-hidden="true" /></button>
