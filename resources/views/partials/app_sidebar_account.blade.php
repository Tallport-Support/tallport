{{-- The sidebar's footer: notifications and the account menu. --}}
@php
    $web_notifications_info = Auth::user()->getWebsiteNotificationsInfo();
@endphp
<x-fruit::floating-disclosure placement="above" class="web-notifications">
    <x-slot:trigger :class="'f-button f-button--ghost f-button--icon web-notifications-trigger'.($web_notifications_info['unread_count'] ? ' has-unread' : '')" :aria-label="__('Notifications')" :title="__('Notifications')">
        <x-heroicon-o-bell class="f-icon" aria-hidden="true" />
    </x-slot:trigger>
    <x-slot:content class="web-notifications-panel">
        <div class="web-notifications-header">
            <h2>
                {{ __('Notifications') }}
                <small class="web-notifications-count f-badge @if (!(int)$web_notifications_info['unread_count']) hidden @endif" title="{{ __('Unread Notifications') }}">@if ($web_notifications_info['unread_count']){{ $web_notifications_info['unread_count'] }}@endif</small>
            </h2>
            <a href="#" class="web-notifications-mark-read @if (!(int)$web_notifications_info['unread_count']) hidden @endif" data-loading-text="{{ __('Processing') }}…">{{ __('Mark all as read') }}</a>
        </div>
        <ul class="web-notifications-list">
            @if (count($web_notifications_info['data']))
                @if (!empty($web_notifications_info['html']))
                    {!! $web_notifications_info['html'] !!}
                @else
                    @include('users/partials/web_notifications', ['web_notifications_info_data' => $web_notifications_info['data']])
                @endif
                @if ($web_notifications_info['notifications']->hasMorePages())
                    <li class="web-notification-more">
                        <button type="button" class="f-button f-button--ghost btn" data-loading-text="{{ __('Loading') }}…">{{ __('Load more') }}</button>
                    </li>
                @endif
            @else
                <li class="web-notifications-empty">
                    <x-fruit::empty-state>
                        <x-slot:title>{{ __('Notifications will start showing up here soon') }}</x-slot:title>
                        <a href="{{ route('users.notifications', ['id' => Auth::user()->id]) }}">{{ __('Update your notification settings') }}</a>
                    </x-fruit::empty-state>
                </li>
            @endif
        </ul>
    </x-slot:content>
</x-fruit::floating-disclosure>

<x-fruit::menu :title="__('Account')" placement="above" class="app-sidebar__account">
    <x-slot:trigger class="f-button--ghost app-sidebar__account-trigger" :aria-label="__('Account')">
        <span class="photo-sm">@include('partials/person_photo', ['person' => Auth::user()])</span>
        <span class="app-sidebar__account-name">{{ Auth::user()->getFullName() }}@action('menu.user.name_append', Auth::user())</span>
    </x-slot:trigger>
    <x-fruit::menu-link :href="route('users.profile', ['id' => Auth::user()->id])">{{ __('Your Profile') }}</x-fruit::menu-link>
    @if (Auth::user()->hasKeyboardShortcuts())
        <x-fruit::menu-link href="#" data-toggle="modal" data-target="#keyboard-shortcuts-modal" shortcut="?">{{ __('Keyboard Shortcuts') }}</x-fruit::menu-link>
    @endif
    <ul class="app-sidebar__module-items">@action('menu_right.user.after_profile')</ul>
    <x-fruit::menu-separator />
    <x-fruit::menu-link href="#" id="logout-link">{{ __('Log Out') }}</x-fruit::menu-link>
    <x-fruit::menu-link href="#" class="hidden in-app-switcher">{{ __('Switch Helpdesk URL') }}</x-fruit::menu-link>
</x-fruit::menu>
<form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">
    {{ csrf_field() }}
</form>
