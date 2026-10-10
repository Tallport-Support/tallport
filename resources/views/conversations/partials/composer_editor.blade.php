{{-- The composers' files and editor (livewire/conversation-composer, livewire/new-conversation):
     $attachments, $body, $formats (the formatting allowed: null all, [] plain text), $placeholder,
     $draft_button, $placeholders (by mode, for a composer that switches); $inline (the chat view): FruitUI's chat field, Enter sends (App\Misc\KeyboardShortcuts). --}}
@php $inline = !empty($inline); @endphp
@if (collect($attachments)->where('embed', false)->count())
    <ul class="conv-composer__attachments">
        @foreach ($attachments as $attachment)
            @if (empty($attachment['embed']))
                <li class="attachment-loaded" wire:key="attachment-{{ md5($attachment['id']) }}">
                    <a href="{{ $attachment['url'] }}" target="_blank">{{ $attachment['name'] }}</a>
                    <span class="f-muted">({{ \Helper::humanFileSize($attachment['size']) }})</span>
                    <button type="button" class="f-button f-button--ghost f-button--icon f-button--small" wire:click="removeAttachment(@js($attachment['id']))" aria-label="{{ __('Remove') }}: {{ $attachment['name'] }}" title="{{ __('Remove') }}"><x-icon.x class="f-icon" aria-hidden="true" /></button>
                </li>
            @endif
        @endforeach
    </ul>
@endif
<ul class="conv-composer__uploading" x-show="uploading.length" x-cloak>
    <template x-for="name in uploading"><li><x-fruit::spinner /> <span x-text="name"></span></li></template>
</ul>

{{-- A new editor when the formats change (a reply's channel, a note): it reads them when it starts. --}}
<div class="conv-reply-body" wire:ignore @if (!empty($placeholders)) data-placeholders="{{ json_encode($placeholders) }}" @endif wire:key="editor-{{ $inline ? 'inline-' : '' }}{{ $formats === null ? 'all' : (implode('-', $formats) ?: 'plain') }}">
    <x-editor id="body" :rows="$inline ? null : 8" :layout="$inline ? 'inline' : 'stacked'" :enter="App\Misc\KeyboardShortcuts::editorEnter($inline ? 'chat' : 'message')" :formats="$formats" :paste="$formats === [] ? 'plain' : 'rich'" :upload-url="route('conversations.upload')" :aria-label="__('Message')" :aria-describedby="$inline ? 'body-help' : null" :placeholder="$placeholder ?? null">
        {{ $body }}
        <x-slot:extras>
            <span class="editor-attach">
                <button type="button" class="f-button f-button--ghost f-button--icon" aria-label="{{ __('Upload Attachments') }}" title="{{ __('Upload Attachments') }}" x-on:click="$refs.files.click()"><x-icon.paperclip class="f-icon" aria-hidden="true" /></button>
                <input type="file" multiple hidden x-ref="files" x-on:change="upload($el.files); $el.value = ''">
            </span>
            @include('conversations/partials/editor_pickers')
            @if ($inline)
                {{-- In the chat field: the pickers and Attach, Draft with AI (written into the field), and Send for touch screens (no Shift+Enter there). --}}
                @action('conversation.editor_extras', $conversation, $mailbox)
                @if (App\Ai\Drafts::allowed(Auth::user(), $conversation))
                    <button type="button" class="f-button f-button--ghost f-button--icon ai-draft-action" x-on:click="$dispatch('ai-draft-request')" aria-label="{{ __('Draft with AI') }}" title="{{ __('Draft with AI') }}"><x-icon.sparkles class="f-icon" aria-hidden="true" /></button>
                @endif
                <button type="submit" class="f-button f-button--primary f-button--icon f-composer__send" aria-label="{{ __('Send') }}" title="{{ __('Send') }}" wire:loading.attr="aria-busy" wire:target="send"><x-icon.send class="f-icon" aria-hidden="true" /></button>
            @else
            @if ($formats !== [])
                <button type="button" class="f-button f-button--ghost f-button--icon" x-data="editorPlainPaste" x-on:click="toggle()" x-bind:aria-pressed="plain ? 'true' : 'false'" aria-pressed="false" aria-label="{{ __('Paste as Plain Text') }}" title="{{ __('Paste as Plain Text') }}"><x-icon.clipboard class="f-icon" aria-hidden="true" /></button>
            @endif
            @action('conversation.editor_extras', $conversation, $mailbox)
            {{-- Saved shows in the spacer, which has no width of its own: it never wraps the toolbar. --}}
            <span class="f-toolbar__spacer draft-saved"><span role="status" x-text="saved ? @js(__('Saved')) : ''"></span></span>
            {{-- Save Draft and Discard move together when the toolbar wraps. --}}
            <span class="composer-draft-actions">
            @if ($draft_button)
                <button type="button" class="f-button f-button--ghost f-button--icon note-btn-save-draft" aria-label="{{ __('Save Draft') }}" title="{{ __('Save Draft') }}" x-on:click="save(true)"><x-icon.check class="f-icon" aria-hidden="true" /></button>
            @endif
            <button type="button" class="f-button f-button--ghost f-button--icon note-btn-discard" aria-label="{{ __('Discard') }}" title="{{ __('Discard') }}" x-on:click="discard()"><x-icon.trash-2 class="f-icon" aria-hidden="true" /></button>
            </span>
            @endif
        </x-slot:extras>
    </x-editor>
</div>
@if ($inline)
    <p class="f-sr-only" id="body-help">{{ __('Enter to send, Shift+Enter for a new line.') }}</p>
@endif
