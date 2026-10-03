{{-- The app's sidebar: search, All Mailboxes and the mailboxes with their folders, the rest of the app, notifications and the account. --}}
@php
    $sidebar_user = Auth::user();
    [$sidebar_mailbox_id, $sidebar_folder_id] = App\Misc\Sidebar::current(['mailbox' => $mailbox ?? null, 'folder' => $folder ?? null]);
    $sidebar_all = App\Misc\AllMailboxes::isAvailable($sidebar_user);
    $sidebar_mailboxes = App\Misc\Sidebar::mailboxes($sidebar_user);
    // The mailbox "New Conversation" (shortcut) is for: the current one, else the first.
    $sidebar_new_mailbox_id = collect($sidebar_mailboxes)->pluck(0)->pluck('id')->contains($sidebar_mailbox_id) ? $sidebar_mailbox_id : ($sidebar_mailboxes[0][0]->id ?? null);
@endphp
<nav class="fruit-ui f-sidebar app-sidebar" id="app-sidebar" aria-label="{{ __('Navigation') }}" @if (Helper::isLocaleRtl()) dir="rtl" @endif>
    <div class="f-sidebar__header app-sidebar__header">
        @if (\Helper::isInApp() && \Helper::isRoute('conversations.view'))
            <a class="f-button f-button--ghost f-button--icon" id="navbar-back" href="#" title="{{ __('Back') }}" aria-label="{{ __('Back') }}"><x-heroicon-o-arrow-left class="f-icon" /></a>
        @endif
        <a class="app-sidebar__brand" href="{{ route('dashboard', ['dashboard' => 1]) }}" title="{{ __('Dashboard') }}">
            @if (($sidebar_logo = \Eventy::filter('layout.header_logo', '')) !== '')
                <img src="{{ $sidebar_logo }}" alt="{{ __('Dashboard') }}" />
            @else
                {{-- img/logo-brand.svg in the brand colour. --}}
                <svg class="app-sidebar__logo" viewBox="0 0 208 208" role="img" aria-label="{{ __('Dashboard') }}"><path fill="currentColor" fill-rule="evenodd" d="M54 0H154A54 54 0 0 1 208 54V154A54 54 0 0 1 154 208H54A54 54 0 0 1 0 154V54A54 54 0 0 1 54 0ZM52 54H156C169 54 179 64 179 77V87C166 88 156 99 156 112C156 125 166 136 179 137V150C179 163 169 173 156 173H84L55 196C49 201 40 197 40 189V137C53 136 63 125 63 112C63 99 53 88 40 87V77C40 64 40 54 52 54Z"/></svg>
            @endif
        </a>
        <form class="app-sidebar__search" role="search" action="{{ route('conversations.search') }}">
            <x-fruit::search name="q" :label="__('Search')" :placeholder="__('Search')" id="search-dt" />
        </form>
    </div>

    @if ($sidebar_all)
        <p class="f-sidebar__heading">{{ __('All Mailboxes') }}</p>
        @php $all_folders = App\Misc\AllMailboxes::folders($sidebar_user); @endphp
        @foreach (App\Misc\Sidebar::visibleFolders($all_folders, $sidebar_mailbox_id == App\Misc\AllMailboxes::MAILBOX_ID ? $sidebar_folder_id : null) as $sidebar_folder)
            @php $sidebar_count = App\Misc\Sidebar::count($sidebar_folder, $all_folders); @endphp
            <a href="{{ route('mailboxes.all', ['folder_id' => $sidebar_folder->id]) }}" class="f-sidebar__item" @if ($sidebar_mailbox_id == App\Misc\AllMailboxes::MAILBOX_ID && $sidebar_folder_id == $sidebar_folder->id) aria-current="page" @endif data-folder_id="{{ $sidebar_folder->id }}" data-mailbox_id="{{ App\Misc\AllMailboxes::MAILBOX_ID }}"><x-dynamic-component :component="App\Misc\Sidebar::folderIcon($sidebar_folder)" class="f-icon" aria-hidden="true" /><span class="f-sidebar__identity">{{ $sidebar_folder->getTypeName() }}</span>@if ($sidebar_count)<span class="f-badge active-count">{{ $sidebar_count }}</span>@endif</a>
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
                @if ($sidebar_mailbox->isArchived())<x-heroicon-o-lock-closed class="f-icon" aria-hidden="true" />@else<x-heroicon-o-envelope class="f-icon" aria-hidden="true" />@endif
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
                <a href="{{ route('conversations.create', ['mailbox_id' => $sidebar_mailbox->id]) }}" class="f-button f-button--ghost f-button--small @if ($sidebar_mailbox->id == $sidebar_new_mailbox_id) new-conversation-link @endif"><x-heroicon-o-pencil-square class="f-icon" aria-hidden="true" /> {{ __('New Conversation') }}</a>
                <x-fruit::menu :title="__('Mailbox')" class="app-sidebar__mailbox-menu">
                    <x-slot:trigger class="f-button--ghost f-button--icon f-button--small" :aria-label="__('More')"><x-heroicon-o-ellipsis-horizontal class="f-icon" aria-hidden="true" /></x-slot:trigger>
                    @if ($sidebar_user->can('update', $sidebar_mailbox))
                        <x-fruit::menu-link :href="route('mailboxes.update', ['id' => $sidebar_mailbox->id])">{{ __('Mailbox Settings') }}</x-fruit::menu-link>
                    @endif
                    <x-fruit::menu-link :href="route('conversations.ajax_html', ['action' => 'default_redirect']).'?mailbox_id='.$sidebar_mailbox->id" data-trigger="modal" :data-modal-title="__('Default Redirect')" data-modal-no-footer="true" data-modal-on-show="initAfterSendModal">{{ __('Default Redirect') }}…</x-fruit::menu-link>
                    <x-fruit::menu-link href="#" class="mailbox-mute-trigger" :data-mute="$sidebar_muted ? '0' : '1'" :data-mailbox-id="$sidebar_mailbox->id" :data-loading-text="__('Processing').'…'"><span class="mute-text-1 @if ($sidebar_muted) hidden @endif">{{ __('Mute Notifications') }}</span><span class="mute-text-0 @if (!$sidebar_muted) hidden @endif">{{ __('Unmute Notifications') }}</span></x-fruit::menu-link>
                    <ul class="app-sidebar__module-items">@action('mailbox.sidebar.buttons', $sidebar_mailbox)</ul>
                </x-fruit::menu>
            </div>
            @action('mailbox.after_sidebar_buttons')
        </details>
    @endforeach

    <hr class="app-sidebar__separator">
    <x-fruit::sidebar-item :href="route('kb')" :current="\App\Misc\Helper::isMenuSelected('kb')">
        <x-slot:icon><x-heroicon-o-book-open class="f-icon" aria-hidden="true" /></x-slot:icon>
        {{ __('Knowledge Base') }}
    </x-fruit::sidebar-item>
    @if (App\Http\Controllers\ReportsController::canAccess($sidebar_user))
        <x-fruit::sidebar-item :href="route('reports.conversations')" :current="\App\Misc\Helper::isMenuSelected('reports')">
            <x-slot:icon><x-heroicon-o-chart-bar class="f-icon" aria-hidden="true" /></x-slot:icon>
            {{ __('Reports') }}
        </x-fruit::sidebar-item>
    @endif
    @if ($sidebar_user->isAdmin()
        || $sidebar_user->hasPermission(App\User::PERM_EDIT_USERS)
        || $sidebar_user->can('viewMailboxMenu', $sidebar_user)
        || Eventy::filter('menu.manage.can_view', false)
    )
        <details class="f-sidebar__group" @if (\App\Misc\Helper::isMenuSelected('manage')) open @endif>
            <summary class="f-sidebar__item">
                <x-heroicon-o-wrench-screwdriver class="f-icon" aria-hidden="true" />
                <span class="f-sidebar__identity">{{ __('Manage') }}</span>
                <svg class="f-icon f-sidebar__chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg>
            </summary>
            @if ($sidebar_user->isAdmin())
                <x-fruit::sidebar-item :href="route('settings')" :current="\App\Misc\Helper::isMenuSelected('settings')">{{ __('Settings') }}</x-fruit::sidebar-item>
            @endif
            @if ($sidebar_user->can('viewMailboxMenu', $sidebar_user))
                <x-fruit::sidebar-item :href="route('mailboxes')" :current="\App\Misc\Helper::isMenuSelected('mailboxes')">{{ __('Mailboxes') }}</x-fruit::sidebar-item>
            @endif
            <ul class="app-sidebar__module-items">@action('menu.manage.after_mailboxes')</ul>
            @if ($sidebar_user->isAdmin() || $sidebar_user->hasPermission(App\User::PERM_EDIT_USERS))
                <x-fruit::sidebar-item :href="route('users')" :current="\App\Misc\Helper::isMenuSelected('users')">{{ __('Users') }}</x-fruit::sidebar-item>
            @endif
            @if ($sidebar_user->isAdmin())
                <x-fruit::sidebar-item :href="route('workflows')" :current="\App\Misc\Helper::isMenuSelected('workflows')">{{ __('Workflows') }}</x-fruit::sidebar-item>
                <x-fruit::sidebar-item :href="route('modules')" :current="\App\Misc\Helper::isMenuSelected('modules')">{{ __('Modules') }}</x-fruit::sidebar-item>
                <x-fruit::sidebar-item :href="route('logs')" :current="\App\Misc\Helper::isMenuSelected('logs')">{{ __('Logs') }}</x-fruit::sidebar-item>
                <x-fruit::sidebar-item :href="route('system')" :current="\App\Misc\Helper::isMenuSelected('system')">{{ __('System') }}</x-fruit::sidebar-item>
            @endif
            <ul class="app-sidebar__module-items">@action('menu.manage.append')</ul>
        </details>
    @endif
    <ul class="app-sidebar__module-items">@action('menu.append')</ul>

    <div class="f-sidebar__footer app-sidebar__footer">
        @include('partials/app_sidebar_account')
    </div>
</nav>
