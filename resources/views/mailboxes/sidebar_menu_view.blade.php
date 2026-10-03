<div class="dropdown sidebar-title sidebar-title-extra @if (!$mailbox->email) sidebar-no-email @endif">
    @if (isset($folder))<span class="sidebar-title-extra-value active-count">{{ $folder->getTypeName() }} ({{ $folder->active_count }})</span>@endif
    @action('mailbox.view.before_name', $mailbox)
    <span class="sidebar-title-real mailbox-name">@if ($mailbox->isArchived())<small class="glyphicon glyphicon-lock text-help"></small> @endif{{ ''}}@include('mailboxes/partials/mute_icon', ['mailbox' => $mailbox]){{ $mailbox->name }}</span>
    <span class="sidebar-title-email">{{ $mailbox->email }}</span>
</div>
@php
    $is_in_chat_mode = $is_in_chat_mode ?? (isset($conversation) && $conversation->isInChatMode());
@endphp
@if (!$is_in_chat_mode && App\Misc\AllMailboxes::isAvailable())
    {{-- All Mailboxes first, then each mailbox (collapsible). --}}
    @php
        $sidebar_folder = $folder ?? new App\Folder();
        $sidebar_in_all = App\Misc\AllMailboxes::isAllMailboxes($mailbox->id);
    @endphp
    <ul class="sidebar-menu sidebar-mailboxes" id="folders" data-mailbox-tree="1">
        <li class="sidebar-mailbox-heading @if ($sidebar_in_all) current @endif" data-mailbox_id="{{ App\Misc\AllMailboxes::MAILBOX_ID }}">
            <a href="{{ route('mailboxes.all') }}"><i class="glyphicon glyphicon-inbox"></i> <span class="folder-name">{{ __('All Mailboxes') }}</span></a>
        </li>
        @include('mailboxes/partials/folders', [
            'mailbox' => App\Misc\AllMailboxes::mailbox(),
            'folders' => $sidebar_in_all ? $folders : App\Misc\AllMailboxes::folders(Auth::user()),
            'folder'  => $sidebar_in_all ? $sidebar_folder : new App\Folder(),
            'folders_hidden' => false,
        ])
        @foreach (Auth::user()->mailboxesCanView(true) as $sidebar_mailbox)
            @php
                $sidebar_current = $sidebar_mailbox->id == $mailbox->id;
                $sidebar_folders = $sidebar_current ? $folders : $sidebar_mailbox->getAssesibleFolders();
                $sidebar_count = $sidebar_folders->whereIn('type', [App\Folder::TYPE_UNASSIGNED, App\Folder::TYPE_MINE])->sum(function ($item) use ($sidebar_folders) {
                    return $item->getCount($sidebar_folders);
                });
            @endphp
            <li class="sidebar-mailbox-heading @if ($sidebar_current) current expanded @endif" data-mailbox_id="{{ $sidebar_mailbox->id }}">
                <a href="{{ $sidebar_mailbox->url() }}"><i class="glyphicon glyphicon-triangle-right sidebar-mailbox-toggle" title="{{ __('Show folders') }}"></i> <span class="folder-name">@if ($sidebar_mailbox->isArchived())<small class="glyphicon glyphicon-lock"></small> @endif{{ $sidebar_mailbox->name }}</span>@if ($sidebar_count)<span class="active-count pull-right">{{ $sidebar_count }}</span>@endif</a>
            </li>
            @include('mailboxes/partials/folders', [
                'mailbox' => $sidebar_mailbox,
                'folders' => $sidebar_folders,
                'folder'  => $sidebar_current ? $sidebar_folder : new App\Folder(),
                'folders_hidden' => !$sidebar_current,
            ])
        @endforeach
    </ul>
@else
<ul class="sidebar-menu @if ($is_in_chat_mode) chats @endif" id="folders">
    @if ($is_in_chat_mode)
        @include('mailboxes/partials/chat_list')
    @else
        @include('mailboxes/partials/folders')
    @endif
</ul>
@endif
@if (!$is_in_chat_mode)
    @php
        $show_settings_btn = Auth::user()->can('viewMailboxMenu', Auth::user());
    @endphp
    @if (\Eventy::filter('mailbox.show_buttons', true, $mailbox))
        <div class="sidebar-buttons btn-group btn-group-justified @if ($show_settings_btn) has-settings @endif">
            @if ($show_settings_btn)
                <div class="btn-group dropdown" data-toggle="tooltip" title="{{ __("Mailbox Settings") }}">
                    <a class="btn btn-trans dropdown-toggle" data-toggle="dropdown" href="#"><i class="glyphicon glyphicon-cog"></i> <b class="caret"></b></a>
                    <ul class="dropdown-menu" role="menu">
                        @include("mailboxes/settings_menu", ['is_dropdown' => true])
                    </ul>
                </div>
            @endif
            @action('mailbox.sidebar.buttons', $mailbox)
            <a class="btn btn-trans" href="{{ route('conversations.create', ['mailbox_id' => $mailbox->id]) }}" aria-label="{{ __("New Conversation") }}" data-toggle="tooltip" title="{{ __("New Conversation") }}" role="button"><i class="glyphicon glyphicon-envelope"></i></a>
        </div>
    @endif
    @action('mailbox.after_sidebar_buttons')
@endif