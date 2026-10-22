{{-- A list of conversations (App\Livewire\ConversationList). --}}
<div class="conv-list-host">
    @include('conversations/conversations_table')
    {{-- What a row drawn from the browser's copy uses (conversations.js, public/js/inbox.js). --}}
    <template id="conv-row-parts">
        <x-icon.message-square class="f-icon" aria-hidden="true" data-part="chat" />
        <x-icon.phone class="f-icon" aria-hidden="true" data-part="phone" />
        <x-icon.user class="f-icon" aria-hidden="true" data-part="user" />
        <x-icon.paperclip class="f-icon" role="img" data-part="attachments" />
        <x-icon.lock class="f-icon conv-badge" role="img" data-part="closed" />
        <x-icon.ban class="f-icon conv-badge" role="img" data-part="spam" />
        <x-icon.copy class="f-icon conv-badge" role="img" data-part="drafts" />
        <x-fruit::badge class="conv-deleted" data-part="deleted">{{ __('Deleted') }}</x-fruit::badge>
        {{-- A list's header where the page has none to copy (an empty folder's): its height. --}}
        <x-fruit::list-header class="conv-list__header" data-part="header"><span class="f-button f-button--ghost f-button--small" aria-hidden="true">&nbsp;</span></x-fruit::list-header>
        <x-fruit::empty-state data-part="empty">
            <x-slot:icon><x-icon.inbox /></x-slot:icon>
            {{ __('There are no conversations here') }}
        </x-fruit::empty-state>
    </template>
</div>
