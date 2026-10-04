{{-- The composers' files and editor (livewire/conversation-composer, livewire/new-conversation):
     $attachments, $body, $plain (plain text only), $placeholder, $draft_button. --}}
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
    <x-editor id="body" rows="8" :paste="$plain ? 'plain' : 'rich'" :upload-url="route('conversations.upload')" :aria-label="__('Message')" :placeholder="$placeholder ?? null">
        {{ $body }}
        <x-slot:extras>
            <span class="editor-attach">
                <button type="button" class="f-button f-button--ghost f-button--icon" aria-label="{{ __('Upload Attachments') }}" title="{{ __('Upload Attachments') }}" x-on:click="$refs.files.click()"><x-heroicon-o-paper-clip class="f-icon" aria-hidden="true" /></button>
                <input type="file" multiple hidden x-ref="files" x-on:change="upload($el.files); $el.value = ''">
            </span>
            @include('conversations/partials/editor_pickers')
            @if (!$plain)
                <button type="button" class="f-button f-button--ghost f-button--icon" x-data="editorPlainPaste" x-on:click="toggle()" x-bind:aria-pressed="plain ? 'true' : 'false'" aria-pressed="false" aria-label="{{ __('Paste as Plain Text') }}" title="{{ __('Paste as Plain Text') }}"><x-heroicon-o-clipboard-document class="f-icon" aria-hidden="true" /></button>
            @endif
            @action('conversation.editor_extras', $conversation, $mailbox)
            <span class="f-toolbar__spacer"></span>
            <span class="draft-saved f-footnote f-muted" x-show="saved" x-transition.opacity x-cloak role="status">{{ __('Saved') }}</span>
            @if ($draft_button)
                <button type="button" class="f-button f-button--ghost f-button--icon note-btn-save-draft" aria-label="{{ __('Save Draft') }}" title="{{ __('Save Draft') }}" x-on:click="save(true)"><x-heroicon-o-check class="f-icon" aria-hidden="true" /></button>
            @endif
            <button type="button" class="f-button f-button--ghost f-button--icon note-btn-discard" aria-label="{{ __('Discard') }}" title="{{ __('Discard') }}" x-on:click="discard()"><x-heroicon-o-trash class="f-icon" aria-hidden="true" /></button>
        </x-slot:extras>
    </x-editor>
</div>
