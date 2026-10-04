{{-- The app's sidebar: search, All Mailboxes and the mailboxes with their folders, the rest of the app, notifications and the account. --}}
@php
    $sidebar_user = Auth::user();
    [$sidebar_mailbox_id, $sidebar_folder_id] = App\Misc\Sidebar::current(['mailbox' => $mailbox ?? null, 'folder' => $folder ?? null]);
    $sidebar_all = App\Misc\AllMailboxes::isAvailable($sidebar_user);
    $sidebar_mailboxes = App\Misc\Sidebar::mailboxes($sidebar_user);
    // The mailbox "New Conversation" (shortcut) is for: the current one, else the first.
    $sidebar_new_mailbox_id = collect($sidebar_mailboxes)->pluck(0)->pluck('id')->contains($sidebar_mailbox_id) ? $sidebar_mailbox_id : ($sidebar_mailboxes[0][0]->id ?? null);
@endphp
<nav class="f-pane f-pane--column f-pane--scroll f-pane--border-end f-sidebar app-sidebar" id="app-sidebar" aria-label="{{ __('Navigation') }}" @if (Helper::isLocaleRtl()) dir="rtl" @endif>
    @if (empty($has_list))
        {{-- With a list, search sits at the top of the list pane. --}}
        <div class="f-sidebar__header app-sidebar__header">
            <form class="app-sidebar__search" role="search" action="{{ route('conversations.search') }}">
                <x-fruit::search name="q" :label="__('Search')" :placeholder="__('Search')" id="search-dt" />
            </form>
        </div>
    @endif

    @if (App\Misc\Sidebar::isSettings())
        @include('partials/app_sidebar_settings')
    @else
    @if ($sidebar_all)
        <p class="f-sidebar__heading">{{ __('All Mailboxes') }}</p>
        @php $all_folders = App\Misc\AllMailboxes::folders($sidebar_user); @endphp
        @foreach (App\Misc\Sidebar::visibleFolders($all_folders, $sidebar_mailbox_id == App\Misc\AllMailboxes::MAILBOX_ID ? $sidebar_folder_id : null) as $sidebar_folder)
            @php $sidebar_count = App\Misc\Sidebar::count($sidebar_folder, $all_folders); @endphp
            <a href="{{ route('mailboxes.all', ['folder_id' => $sidebar_folder->id]) }}" class="f-sidebar__item" wire:navigate @if ($sidebar_mailbox_id == App\Misc\AllMailboxes::MAILBOX_ID && $sidebar_folder_id == $sidebar_folder->id) aria-current="page" @endif data-folder_id="{{ $sidebar_folder->id }}" data-mailbox_id="{{ App\Misc\AllMailboxes::MAILBOX_ID }}"><x-dynamic-component :component="App\Misc\Sidebar::folderIcon($sidebar_folder)" class="f-icon" aria-hidden="true" /><span class="f-sidebar__identity">{{ $sidebar_folder->getTypeName() }}</span>@if ($sidebar_count)<span class="f-badge active-count">{{ $sidebar_count }}</span>@endif</a>
        @endforeach
    @endif

    <p class="f-sidebar__heading">{{ $sidebar_all ? __('Mailboxes') : __('Mailbox') }}</p>
    @foreach ($sidebar_mailboxes as [$sidebar_mailbox, $sidebar_folders])
        @php
            $sidebar_is_current = $sidebar_mailbox_id == $sidebar_mailbox->id;
            $sidebar_muted = (bool) $sidebar_user->mailboxSettings($sidebar_mailbox->id)->mute;
            $sidebar_open_count = $sidebar_folders->whereIn('type', [App\Folder::TYPE_UNASSIGNED, App\Folder::TYPE_MINE])->sum(fn ($item) => $item->getCount($sidebar_folders));
        @endphp
        <details class="f-sidebar__group app-sidebar__mailbox" data-mailbox_id="{{ $sidebar_mailbox->id }}" @if ($sidebar_is_current || (!$sidebar_all && count($sidebar_mailboxes) == 1)) open @endif>
            <summary class="f-sidebar__item">
                @if ($sidebar_mailbox->isArchived())<x-icon.lock class="f-icon" aria-hidden="true" />@else<x-icon.mail class="f-icon" aria-hidden="true" />@endif
                <span class="f-sidebar__identity mailbox-name">
                    @if (count($sidebar_mailboxes) == 1)
                        @action('menu.mailbox_single.before_name', $sidebar_mailbox)
                    @else
                        @action('menu.mailbox.before_name', $sidebar_mailbox)
                    @endif
                    {{ $sidebar_mailbox->name }}
                    @if (count($sidebar_mailboxes) == 1)
                        @action('menu.mailbox_single.after_name', $sidebar_mailbox)
                    @else
                        @action('menu.mailbox.after_name', $sidebar_mailbox)
                    @endif
                </span>
                @if ($sidebar_open_count)<span class="f-badge">{{ $sidebar_open_count }}</span>@endif
                <svg class="f-icon f-sidebar__chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg>
            </summary>
            <div class="app-sidebar__folders" data-mailbox_id="{{ $sidebar_mailbox->id }}">
                @include('partials/app_sidebar_folders', ['sidebar_mailbox' => $sidebar_mailbox, 'sidebar_folders' => $sidebar_folders, 'sidebar_current_folder_id' => $sidebar_is_current ? $sidebar_folder_id : null])
            </div>
            <div class="app-sidebar__mailbox-actions">
                <a href="{{ route('conversations.create', ['mailbox_id' => $sidebar_mailbox->id]) }}" class="f-button f-button--ghost f-button--small @if ($sidebar_mailbox->id == $sidebar_new_mailbox_id) new-conversation-link @endif"><x-icon.square-pen class="f-icon" aria-hidden="true" /> {{ __('New Conversation') }}</a>
                <x-fruit::menu :title="__('Mailbox')" class="app-sidebar__mailbox-menu">
                    <x-slot:trigger class="f-button--ghost f-button--icon f-button--small" :aria-label="__('More')"><x-icon.ellipsis class="f-icon" aria-hidden="true" /></x-slot:trigger>
                    @if ($sidebar_user->can('updateSettings', $sidebar_mailbox) || $sidebar_user->can('updateEmailSignature', $sidebar_mailbox))
                        {{-- Name and signature in a dialog (mailboxes/quick_settings), with a way to all of them. --}}
                        <x-fruit::menu-link :href="route('mailboxes.quick_settings', ['id' => $sidebar_mailbox->id])" data-fruit-dialog-url :data-fruit-dialog-title="$sidebar_mailbox->name">{{ __('Mailbox Settings') }}</x-fruit::menu-link>
                    @elseif ($sidebar_user->can('update', $sidebar_mailbox))
                        <x-fruit::menu-link :href="route('mailboxes.update', ['id' => $sidebar_mailbox->id])">{{ __('Mailbox Settings') }}</x-fruit::menu-link>
                    @endif
                    <x-fruit::menu-item x-data="tallportMuteMailbox({{ $sidebar_mailbox->id }}, {{ $sidebar_muted ? 'true' : 'false' }})" x-on:click="toggle"><span x-text="muted ? @js(__('Unmute Notifications')) : @js(__('Mute Notifications'))">{{ $sidebar_muted ? __('Unmute Notifications') : __('Mute Notifications') }}</span></x-fruit::menu-item>
                    <ul class="app-sidebar__module-items">@action('mailbox.sidebar.buttons', $sidebar_mailbox)</ul>
                </x-fruit::menu>
            </div>
            @action('mailbox.after_sidebar_buttons')
        </details>
    @endforeach

    <hr class="app-sidebar__separator">
    <x-fruit::sidebar-item :href="route('kb')" :current="\App\Misc\Helper::isMenuSelected('kb')">
        <x-slot:icon><x-icon.book-open class="f-icon" aria-hidden="true" /></x-slot:icon>
        {{ __('Knowledge Base') }}
    </x-fruit::sidebar-item>
    @if (App\Http\Controllers\ReportsController::canAccess($sidebar_user))
        <x-fruit::sidebar-item :href="route('reports.conversations')" :current="\App\Misc\Helper::isMenuSelected('reports')">
            <x-slot:icon><x-icon.chart-column class="f-icon" aria-hidden="true" /></x-slot:icon>
            {{ __('Reports') }}
        </x-fruit::sidebar-item>
    @endif
    {{-- Settings: the sidebar then lists them (partials/app_sidebar_settings). --}}
    @php
        $sidebar_settings_url = $sidebar_user->isAdmin() ? route('settings')
            : ($sidebar_user->hasPermission(App\User::PERM_EDIT_USERS) ? route('users')
            : ($sidebar_user->can('viewMailboxMenu', $sidebar_user) ? route('mailboxes')
            : route('users.profile', ['id' => $sidebar_user->id])));
    @endphp
    <x-fruit::sidebar-item :href="$sidebar_settings_url" class="app-sidebar__settings">
        <x-slot:icon><x-icon.settings class="f-icon" aria-hidden="true" /></x-slot:icon>
        {{ __('Settings') }}
    </x-fruit::sidebar-item>
    <ul class="app-sidebar__module-items">@action('menu.append')</ul>
    @endif

    <div class="f-sidebar__footer app-sidebar__footer">
        @include('partials/app_sidebar_account')
    </div>
</nav>
