{{-- Chat mode: the mailbox's chats beside the conversation (the folders are in the app's sidebar). --}}
<div class="sidebar-title sidebar-title-extra @if (!$mailbox->email) sidebar-no-email @endif">
    @action('mailbox.view.before_name', $mailbox)
    <span class="sidebar-title-real mailbox-name">@if ($mailbox->isArchived())<small class="glyphicon glyphicon-lock text-help"></small> @endif{{ ''}}@include('mailboxes/partials/mute_icon', ['mailbox' => $mailbox]){{ $mailbox->name }}</span>
    <span class="sidebar-title-email">{{ $mailbox->email }}</span>
</div>
<ul class="sidebar-menu chats" id="folders">
    @include('mailboxes/partials/chat_list')
</ul>
