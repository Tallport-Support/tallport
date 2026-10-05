{{-- The sidebar's footer: notifications and the account menu. --}}
@php
    $web_notifications_info = Auth::user()->getWebsiteNotificationsInfo();
@endphp
<div class="web-notifications-host" x-data="tallportNotifications({{ (int) $web_notifications_info['unread_count'] }})">
<x-fruit::floating-disclosure placement="above" class="web-notifications">
    <x-slot:trigger class="f-button f-button--ghost f-button--icon web-notifications-trigger" x-bind:class="{ 'has-unread': unread > 0 }" :aria-label="__('Notifications')" :title="__('Notifications')">
        <x-icon.bell class="f-icon" aria-hidden="true" />
    </x-slot:trigger>
    <x-slot:content class="web-notifications-panel">
        <div class="web-notifications-header">
            <h2>
                {{ __('Notifications') }}
                <small class="web-notifications-count f-badge" title="{{ __('Unread Notifications') }}" x-show="unread > 0" x-text="unread">{{ $web_notifications_info['unread_count'] ?: '' }}</small>
            </h2>
            <button type="button" class="f-button f-button--ghost f-button--small web-notifications-mark-read" x-show="unread > 0" x-on:click="markRead($el)">{{ __('Mark all as read') }}</button>
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
                        <button type="button" class="f-button f-button--ghost" x-on:click="more($el)">{{ __('Load More') }}</button>
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
</div>

<x-fruit::menu :title="__('Account')" placement="above" class="app-sidebar__account">
    <x-slot:trigger class="f-button--ghost app-sidebar__account-trigger" :aria-label="__('Account')">
        <span class="photo-sm">@include('partials/person_photo', ['person' => Auth::user()])</span>
        <span class="app-sidebar__account-name">{{ Auth::user()->getFullName() }}@action('menu.user.name_append', Auth::user())</span>
    </x-slot:trigger>
    <x-fruit::menu-link :href="route('users.profile', ['id' => Auth::user()->id])">{{ __('Profile') }}</x-fruit::menu-link>
    <x-fruit::menu-link :href="route('users.security', ['id' => Auth::user()->id])">{{ __('Security') }}</x-fruit::menu-link>
    <x-fruit::menu-link :href="route('users.preferences', ['id' => Auth::user()->id])">{{ __('Preferences') }}</x-fruit::menu-link>
    @if (Auth::user()->can('updateNotifications', Auth::user()))
        <x-fruit::menu-link :href="route('users.notifications', ['id' => Auth::user()->id])">{{ __('Notifications') }}</x-fruit::menu-link>
    @endif
    <x-fruit::menu-link :href="route('users.api_keys', ['id' => Auth::user()->id])">{{ __('API Keys') }}</x-fruit::menu-link>
    <x-fruit::menu-separator />
    @if (Auth::user()->hasKeyboardShortcuts())
        <x-fruit::menu-link href="#" x-on:click.prevent="document.querySelector('[data-fruit-dialog=keyboard-shortcuts]').showModal()" shortcut="?">{{ __('Keyboard Shortcuts') }}</x-fruit::menu-link>
    @endif
    <ul class="app-sidebar__module-items">@action('menu_right.user.after_profile')</ul>
    <x-fruit::menu-separator />
    <x-fruit::menu-link href="#" id="logout-link" x-on:click.prevent="document.getElementById('logout-form').submit()">{{ __('Log Out') }}</x-fruit::menu-link>
</x-fruit::menu>
<form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">
    {{ csrf_field() }}
</form>
