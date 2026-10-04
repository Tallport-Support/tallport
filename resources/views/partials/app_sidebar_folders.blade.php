{{-- A mailbox's folders in the sidebar (also sent when new messages come in: App\Events\RealtimeMailboxNewThread). --}}
@if (\Helper::isChatModeAvailable())
    <x-fruit::sidebar-item :href="route('conversations.chats', ['mailbox_id' => $sidebar_mailbox->id, 'chat_mode' => '1'])" :current="request()->routeIs('conversations.chats') && request()->mailbox_id == $sidebar_mailbox->id">
        <x-slot:icon><x-icon.messages-square class="f-icon" aria-hidden="true" /></x-slot:icon>
        {{ __('Chats') }}
    </x-fruit::sidebar-item>
@endif
@foreach (App\Misc\Sidebar::visibleFolders($sidebar_folders, $sidebar_current_folder_id) as $sidebar_folder)
    @php $sidebar_count = App\Misc\Sidebar::count($sidebar_folder, $sidebar_folders); @endphp
    <a href="{{ $sidebar_folder->url($sidebar_mailbox->id) }}" class="f-sidebar__item" wire:navigate @if ($sidebar_current_folder_id == $sidebar_folder->id) aria-current="page" @endif data-folder_id="{{ $sidebar_folder->id }}" data-active-count="{{ $sidebar_count }}"><x-dynamic-component :component="App\Misc\Sidebar::folderIcon($sidebar_folder)" class="f-icon" aria-hidden="true" /><span class="f-sidebar__identity">{{ $sidebar_folder->getTypeName() }}</span>@if ($sidebar_count)<span class="f-badge active-count">{{ $sidebar_count }}</span>@endif</a>
@endforeach
