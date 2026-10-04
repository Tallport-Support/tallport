{{-- Chat mode: the mailbox's chats in the list column (the folders are in the app's sidebar). --}}
<x-page-nav class="split-view__header">
    <x-slot:title>
        <h1>{{ __('Chats') }}</h1>
        <small class="f-muted mailbox-name">@action('mailbox.view.before_name', $mailbox)@include('mailboxes/partials/mute_icon', ['mailbox' => $mailbox]){{ $mailbox->name }}</small>
    </x-slot:title>
    <x-slot:actions>
        @if (isset($folder))
            <a href="{{ route('mailboxes.view.folder', ['id' => $mailbox->id, 'folder_id' => $folder->id, 'chat_mode' => 0]) }}" class="f-button f-button--small">{{ __('Exit') }}</a>
        @else
            <a href="{{ route('mailboxes.view', ['id' => $mailbox->id, 'chat_mode' => 0]) }}" class="f-button f-button--small">{{ __('Exit') }}</a>
        @endif
    </x-slot:actions>
</x-page-nav>
<ul class="f-item-list chat-list" id="folders" role="list">
    @include('mailboxes/partials/chat_list')
</ul>
