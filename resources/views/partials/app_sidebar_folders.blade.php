{{-- A mailbox's folders in the sidebar (also sent when new messages come in: App\Events\RealtimeMailboxNewThread): the
     unread count, else a dot where active conversations for the user are all read (Sidebar::unreadCounts()). --}}
@php
    $sidebar_visible = App\Misc\Sidebar::visibleFolders($sidebar_folders, $sidebar_current_folder_id, $sidebar_counts['folders']);
    // Spam and Deleted under More, open when the current folder is there.
    [$sidebar_more, $sidebar_main] = $sidebar_visible->partition(fn ($folder) => in_array($folder->type, [App\Folder::TYPE_SPAM, App\Folder::TYPE_DELETED]));
@endphp
@foreach ($sidebar_main as $sidebar_folder)
    @include('partials/app_sidebar_folder')
@endforeach
@if (count($sidebar_more))
    <x-fruit::sidebar-group :title="__('More')" class="app-sidebar__more" :open="$sidebar_more->contains('id', $sidebar_current_folder_id)">
        <x-slot:icon><x-icon.ellipsis class="f-icon" aria-hidden="true" /></x-slot:icon>
        @foreach ($sidebar_more as $sidebar_folder)
            @include('partials/app_sidebar_folder')
        @endforeach
    </x-fruit::sidebar-group>
@endif
