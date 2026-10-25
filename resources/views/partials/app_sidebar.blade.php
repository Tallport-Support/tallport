{{-- The app's sidebar: search, All Mailboxes and the mailboxes with their folders, the rest of the app, notifications and the account. --}}
@php
    $sidebar_user = Auth::user();
    [$sidebar_mailbox_id, $sidebar_folder_id] = App\Misc\Sidebar::current(['mailbox' => $mailbox ?? null, 'folder' => $folder ?? null]);
    $sidebar_all = App\Misc\AllMailboxes::isAvailable($sidebar_user);
    $sidebar_mailboxes = App\Misc\Sidebar::mailboxes($sidebar_user);
    $sidebar_counts = $sidebar_counts ?? (App\Misc\Sidebar::isSettings() ? ['folders' => [], 'mailboxes' => []] : App\Misc\Sidebar::unreadCounts($sidebar_user));
@endphp
@if (!empty($sidebar_column))
{{-- The first level of a drill-down (layouts/app), as FruitUI's settings example: the brand and the
     way back to the inbox at the top, then the sidebar. --}}
<nav class="f-pane--scroll f-sidebar f-drilldown__column app-sidebar app-sidebar--column" id="app-sidebar" aria-label="{{ __('Navigation') }}" @if (Helper::isLocaleRtl()) dir="rtl" @endif>
    <div class="app-sidebar__brand-row">
        @include('partials/app_sidebar_brand')
        <x-fruit::back-link :href="url('/')" wire:navigate class="app-sidebar__back">{{ __('Inbox') }}</x-fruit::back-link>
    </div>
@else
<nav class="f-pane f-pane--column f-pane--scroll f-pane--border-end f-sidebar app-sidebar" id="app-sidebar" aria-label="{{ __('Navigation') }}" @if (Helper::isLocaleRtl()) dir="rtl" @endif>
@endif
    @if (App\Misc\Sidebar::isSettings())
        {{-- Settings' search: finds settings pages by their names and what's on them (tallport.js). --}}
        <div class="f-sidebar__header app-sidebar__header" role="search">
            <x-fruit::search name="settings_q" :label="__('Search Settings')" :placeholder="__('Search Settings')" id="settings-search" class="app-settings-search" />
        </div>
    @elseif (empty($has_list))
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
    @if ($sidebar_mailboxes)
        {{-- Team Chat (App\Livewire\TeamChat): the room opened last; unread messages in all of them (public/js/realtime.js). --}}
        @php $sidebar_team_unread = App\TeamMessage::unreadTotal($sidebar_user); @endphp
        <a href="{{ route('team_chat') }}" wire:navigate class="f-sidebar__item app-team-chat-link" data-label-unread="{{ __('Team Chat, :count unread') }}" @if ($sidebar_team_unread) aria-label="{{ __('Team Chat, :count unread', ['count' => $sidebar_team_unread]) }}" @endif @if (Route::currentRouteName() == 'mailboxes.team_chat') aria-current="page" @endif><x-icon.messages-square class="f-icon" aria-hidden="true" /><span class="f-sidebar__identity">{{ __('Team Chat') }}</span><span class="f-badge f-badge--accent app-team-chat-badge" @if (!$sidebar_team_unread) hidden @endif>{{ $sidebar_team_unread }}</span></a>
    @endif
    @if ($sidebar_all)
        <p class="f-sidebar__heading">{{ __('All Mailboxes') }}</p>
        @php $all_folders = App\Misc\AllMailboxes::folders($sidebar_user); @endphp
        <div class="app-sidebar__folders" data-mailbox_id="{{ App\Misc\AllMailboxes::MAILBOX_ID }}">
            @include('partials/app_sidebar_folders', ['sidebar_mailbox' => App\Misc\AllMailboxes::mailbox(), 'sidebar_folders' => $all_folders, 'sidebar_current_folder_id' => $sidebar_mailbox_id == App\Misc\AllMailboxes::MAILBOX_ID ? $sidebar_folder_id : null])
        </div>
    @endif

    <p class="f-sidebar__heading">{{ $sidebar_all ? __('Mailboxes') : __('Mailbox') }}</p>
    @foreach ($sidebar_mailboxes as [$sidebar_mailbox, $sidebar_folders])
        @php
            $sidebar_is_current = $sidebar_mailbox_id == $sidebar_mailbox->id;
            $sidebar_muted = (bool) $sidebar_user->mailboxSettings($sidebar_mailbox->id)->mute;
            $sidebar_open_count = $sidebar_counts['mailboxes'][$sidebar_mailbox->id] ?? 0;
            $sidebar_mailbox_for_you = !empty($sidebar_counts['for_you']['mailboxes'][$sidebar_mailbox->id]);
            // Its name for screen readers: with its count or dot, and muted notifications (tallportRowLabel()).
            $sidebar_label = $sidebar_open_count ? __(':mailbox, :count unread', ['mailbox' => $sidebar_mailbox->name, 'count' => $sidebar_open_count])
                : ($sidebar_mailbox_for_you ? __(':mailbox, conversations for you, all read', ['mailbox' => $sidebar_mailbox->name]) : null);
            if ($sidebar_muted) {
                $sidebar_label = __(':label, notifications muted', ['label' => $sidebar_label ?? $sidebar_mailbox->name]);
            }
        @endphp
        <details class="f-sidebar__group app-sidebar__mailbox" data-mailbox_id="{{ $sidebar_mailbox->id }}" @if ($sidebar_is_current || (!$sidebar_all && count($sidebar_mailboxes) == 1)) open @endif>
            <summary class="f-sidebar__item" data-fruit-mark="{{ $sidebar_mailbox->accent ?: 'blue' }}" @if ($sidebar_label) aria-label="{{ $sidebar_label }}" @endif data-label-unread="{{ __(':mailbox, :count unread', ['mailbox' => $sidebar_mailbox->name, 'count' => ':count']) }}" data-label-read="{{ __(':mailbox, conversations for you, all read', ['mailbox' => $sidebar_mailbox->name]) }}" data-label-name="{{ $sidebar_mailbox->name }}" data-label-muted="{{ __(':label, notifications muted') }}" data-muted="{{ $sidebar_muted ? 1 : 0 }}" data-unread-count="{{ $sidebar_open_count }}" data-for-you="{{ $sidebar_mailbox_for_you ? 1 : 0 }}" data-dot="{{ __('Conversations for you, all read') }}">
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
                {{-- The user's notifications for it muted (tallportMuteMailbox). --}}
                <x-icon.bell-off class="f-icon app-sidebar__muted" aria-hidden="true" :data-muted-mailbox="$sidebar_mailbox->id" :hidden="!$sidebar_muted" />
                @if ($sidebar_open_count)<span class="f-badge app-sidebar__mailbox-count">{{ $sidebar_open_count }}</span>@elseif ($sidebar_mailbox_for_you)<span class="f-badge f-badge--dot app-sidebar__mailbox-count" role="img" aria-label="{{ __('Conversations for you, all read') }}" title="{{ __('Conversations for you, all read') }}"></span>@endif
                <svg class="f-icon f-sidebar__chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg>
            </summary>
            <div class="app-sidebar__folders" data-mailbox_id="{{ $sidebar_mailbox->id }}">
                @include('partials/app_sidebar_folders', ['sidebar_mailbox' => $sidebar_mailbox, 'sidebar_folders' => $sidebar_folders, 'sidebar_current_folder_id' => $sidebar_is_current ? $sidebar_folder_id : null])
            </div>
            @action('mailbox.after_sidebar_buttons')
        </details>
    @endforeach
    {{-- Each mailbox's commands on its row (a secondary click, Shift+F10 or the menu key), as in the list's
         toolbar while one of its folders is open (mailboxes/partials/mailbox_menu). --}}
    @foreach ($sidebar_mailboxes as [$sidebar_mailbox])
        <x-fruit::context-menu :title="$sidebar_mailbox->name" :targets="'details.app-sidebar__mailbox[data-mailbox_id=\''.$sidebar_mailbox->id.'\'] > summary'">
            @include('mailboxes/partials/mailbox_menu', ['menu_mailbox' => $sidebar_mailbox, 'menu_muted' => (bool) $sidebar_user->mailboxSettings($sidebar_mailbox->id)->mute])
        </x-fruit::context-menu>
    @endforeach

    <ul class="app-sidebar__module-items">@action('menu.append')</ul>
    @endif

    <div class="f-sidebar__footer app-sidebar__footer">
        @include('partials/app_sidebar_account')
    </div>
</nav>
