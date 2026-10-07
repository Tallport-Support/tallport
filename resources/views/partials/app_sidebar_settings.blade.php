{{-- The sidebar on settings pages (App\Misc\Sidebar::isSettings()): the user's account, the app's
     settings and Manage, each where the user may go (back to the inbox: the bar above, layouts/app). --}}
@php
    $settings_route = Route::currentRouteName();
    $settings_own = str_starts_with((string) $settings_route, 'users.') && request()->route('id') == $sidebar_user->id;
    $settings_section = request()->route('section') ?: 'general';
@endphp

{{-- The user's account as a card (as Apple's account in System Settings), its pages under it. --}}
<a href="{{ route('users.profile', ['id' => $sidebar_user->id]) }}" class="f-sidebar__item app-sidebar__account-card" wire:navigate data-search="{{ __('Account') }}">
    @if ($sidebar_user->photo_url)
        <x-fruit::avatar :src="$sidebar_user->getPhotoUrl()" class="app-sidebar__account-photo" />
    @else
        <x-fruit::avatar class="app-sidebar__account-photo">{{ mb_strtoupper(mb_substr((string) $sidebar_user->first_name, 0, 1).mb_substr((string) $sidebar_user->last_name, 0, 1)) }}</x-fruit::avatar>
    @endif
    <span class="f-sidebar__identity"><strong>{{ $sidebar_user->getFullName() }}</strong><small>{{ $sidebar_user->email }}</small></span>
</a>
<div class="app-sidebar__account-pages" role="group" aria-label="{{ __('Account') }}">
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('users/profile')" :href="route('users.profile', ['id' => $sidebar_user->id])" :current="$settings_own && $settings_route == 'users.profile'"><x-slot:icon><x-icon.circle-user class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Profile') }}</x-fruit::sidebar-item>
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('users/security', 'users/password')" :href="route('users.security', ['id' => $sidebar_user->id])" :current="$settings_own && in_array($settings_route, ['users.security', 'users.password'])"><x-slot:icon><x-icon.lock class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Security') }}</x-fruit::sidebar-item>
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('users/preferences')" :href="route('users.preferences', ['id' => $sidebar_user->id])" :current="$settings_own && $settings_route == 'users.preferences'"><x-slot:icon><x-icon.sliders-horizontal class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Preferences') }}</x-fruit::sidebar-item>
        @if ($sidebar_user->can('updateNotifications', $sidebar_user))
            <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('users/notifications')" :href="route('users.notifications', ['id' => $sidebar_user->id])" :current="$settings_own && $settings_route == 'users.notifications'"><x-slot:icon><x-icon.bell class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Notifications') }}</x-fruit::sidebar-item>
        @endif
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('users/api_keys')" :href="route('users.api_keys', ['id' => $sidebar_user->id])" :current="$settings_own && $settings_route == 'users.api_keys'"><x-slot:icon><x-icon.key-round class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('API Keys') }}</x-fruit::sidebar-item>
    </div>

@if ($sidebar_user->isAdmin())
    @php
        $settings_icons = ['general' => 'settings', 'emails' => 'mail', 'alerts' => 'bell-ring', 'ai' => 'sparkles', 'api' => 'webhook', 'retention' => 'archive', 'branding' => 'palette'];
    @endphp
    <p class="f-sidebar__heading">{{ __('Settings') }}</p>
    @foreach (app(App\Http\Controllers\SettingsController::class)->getSections() as $settings_name => $settings_info)
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('settings/'.$settings_name, $settings_name == 'ai' ? 'settings/ai_documents' : '')" :href="route('settings', ['section' => $settings_name])" :current="$settings_route == 'settings' && $settings_section == $settings_name || ($settings_route == 'settings.ai_documents' && $settings_name == 'ai')"><x-slot:icon><x-dynamic-component :component="'icon.'.($settings_icons[$settings_name] ?? 'settings')" class="f-icon" aria-hidden="true" /></x-slot:icon>{{ $settings_info['title'] }}</x-fruit::sidebar-item>
    @endforeach
@endif

@if ($sidebar_user->isAdmin()
    || $sidebar_user->hasPermission(App\User::PERM_EDIT_USERS)
    || $sidebar_user->can('viewMailboxMenu', $sidebar_user)
    || Eventy::filter('menu.manage.can_view', false)
)
    <p class="f-sidebar__heading">{{ __('Manage') }}</p>
    @if ($sidebar_user->can('viewMailboxMenu', $sidebar_user))
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('mailboxes/mailboxes', 'mailboxes/create', 'mailboxes/update', 'mailboxes/connection', 'mailboxes/connection_incoming', 'mailboxes/permissions', 'mailboxes/auto_reply', 'mailboxes/telegram', 'mailboxes/ai', 'nostr/mailbox_settings')" :href="route('mailboxes')" :current="in_array($settings_route, ['mailboxes', 'mailboxes.create']) || (!empty($mailbox) && $mailbox instanceof App\Mailbox && $mailbox->id)"><x-slot:icon><x-icon.inbox class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Mailboxes') }}</x-fruit::sidebar-item>
    @endif
    {{-- A mailbox's pages (mailboxes/sidebar_menu) keep Mailboxes current: they're reached from its list. --}}
    <ul class="app-sidebar__module-items">@action('menu.manage.after_mailboxes')</ul>
    @if ($sidebar_user->isAdmin() || $sidebar_user->hasPermission(App\User::PERM_EDIT_USERS))
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('users/users', 'users/create', 'users/permissions')" :href="route('users')" :current="\App\Misc\Helper::isMenuSelected('users')"><x-slot:icon><x-icon.users class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Users') }}</x-fruit::sidebar-item>
    @endif
    @if ($sidebar_user->isAdmin())
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('modules/modules')" :href="route('modules')" :current="$settings_route == 'modules'"><x-slot:icon><x-icon.puzzle class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Modules') }}</x-fruit::sidebar-item>
        {{-- Status: what needs attention, as System Status found it last (SystemController::PROBLEM_COUNT_CACHE). --}}
        @php $settings_problems = (int) \Cache::get(App\Http\Controllers\SystemController::PROBLEM_COUNT_CACHE); @endphp
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('livewire/system-status')" :href="route('system')" :current="in_array($settings_route, ['system', 'system.tools'])" :aria-label="$settings_problems ? trans_choice('Status, 1 needs attention|Status, :count need attention', $settings_problems) : null"><x-slot:icon><x-icon.server class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Status') }}
            @if ($settings_problems)
                <x-slot:badge class="f-badge--warning">{{ $settings_problems }}</x-slot:badge>
            @endif
        </x-fruit::sidebar-item>
        <x-fruit::sidebar-item wire:navigate :data-search="App\Misc\Sidebar::settingsKeywords('secure/logs')" :href="route('logs')" :current="in_array($settings_route, ['logs', 'logs.app'])"><x-slot:icon><x-icon.file-text class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Logs') }}</x-fruit::sidebar-item>
    @endif
    <ul class="app-sidebar__module-items">@action('menu.manage.append')</ul>
@endif

{{-- Settings' search (partials/app_sidebar): nothing matched. --}}
<p class="app-sidebar__no-results f-help" hidden>{{ __('No Settings Found') }}</p>
