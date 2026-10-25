{{-- The list pane's toolbar: the folder, its mailbox and how many conversations it holds; New Conversation and,
     while one of a mailbox's folders is open, the mailbox's commands. --}}
<div class="app-list-title">
    <h1>{{ $folder->getTypeName() }}</h1>
    <p>@if ($mailbox instanceof App\Mailbox && $mailbox->id > 0)@action('mailbox.view.before_name', $mailbox)@endif @include('mailboxes/partials/mute_icon', ['mailbox' => $mailbox]){{ $mailbox->name }}@if (method_exists($conversations, 'total')) · {{ trans_choice(':count conversation|:count conversations', $conversations->total()) }}@endif</p>
</div>
<span class="f-toolbar__spacer"></span>
@if (($folder->type == App\Folder::TYPE_DELETED || $folder->type == App\Folder::TYPE_SPAM) && $folder->total_count)
    <x-fruit::button variant="danger" size="small" class="mailbox-empty-folder" x-data="tallportEmptyFolder({{ $folder->id }}, {{ $mailbox->id }}, {{ \Illuminate\Support\Js::from(__('Delete the conversations?')) }}, {{ \Illuminate\Support\Js::from(__('Delete')) }})" x-on:click="empty($el)">@if ($folder->type == App\Folder::TYPE_DELETED){{ __('Empty Trash') }}@else{{ __('Delete All') }}@endif</x-fruit::button>
@endif
@php
    $toolbar_mailbox = $mailbox instanceof App\Mailbox && $mailbox->id > 0 ? $mailbox : null;
    // In the mailbox being viewed; from All Mailboxes, the one used last (App\Misc\Sidebar).
    $toolbar_new_mailbox_id = App\Misc\Sidebar::newConversationMailboxId(Auth::user(), $toolbar_mailbox);
@endphp
@if ($toolbar_new_mailbox_id)
    <a href="{{ route('conversations.create', ['mailbox_id' => $toolbar_new_mailbox_id]) }}" class="f-button f-button--ghost f-button--icon new-conversation-link" aria-label="{{ __('New Conversation') }}" title="{{ __('New Conversation') }}"><x-icon.square-pen class="f-icon" aria-hidden="true" /></a>
@endif
@if ($toolbar_mailbox)
    <x-fruit::menu :title="$toolbar_mailbox->name" class="app-list-mailbox-menu">
        <x-slot:trigger class="f-button--ghost f-button--icon" :aria-label="__('More for :name', ['name' => $toolbar_mailbox->name])" :title="__('More')"><x-icon.ellipsis class="f-icon" aria-hidden="true" /></x-slot:trigger>
        @include('mailboxes/partials/mailbox_menu', ['menu_mailbox' => $toolbar_mailbox, 'menu_muted' => (bool) Auth::user()->mailboxSettings($toolbar_mailbox->id)->mute])
    </x-fruit::menu>
@endif
