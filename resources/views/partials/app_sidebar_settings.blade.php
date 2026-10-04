{{-- The sidebar on settings pages (App\Misc\Sidebar::isSettings()): back to the inbox, then the
     account's pages, the app's settings and Manage, each where the user may go. --}}
@php
    $settings_route = Route::currentRouteName();
    $settings_own = str_starts_with((string) $settings_route, 'users.') && request()->route('id') == $sidebar_user->id;
    $settings_section = request()->route('section') ?: 'general';
@endphp
<a href="{{ url('/') }}" class="f-sidebar__item app-sidebar__back" wire:navigate><x-icon.chevron-left class="f-icon" aria-hidden="true" /><span class="f-sidebar__identity">{{ __('Inbox') }}</span></a>

<p class="f-sidebar__heading">{{ __('Account') }}</p>
<x-fruit::sidebar-item :href="route('users.profile', ['id' => $sidebar_user->id])" :current="$settings_own && $settings_route == 'users.profile'"><x-slot:icon><x-icon.circle-user class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Profile') }}</x-fruit::sidebar-item>
<x-fruit::sidebar-item :href="route('users.security', ['id' => $sidebar_user->id])" :current="$settings_own && in_array($settings_route, ['users.security', 'users.password'])"><x-slot:icon><x-icon.lock class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Security') }}</x-fruit::sidebar-item>
<x-fruit::sidebar-item :href="route('users.preferences', ['id' => $sidebar_user->id])" :current="$settings_own && $settings_route == 'users.preferences'"><x-slot:icon><x-icon.sliders-horizontal class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Preferences') }}</x-fruit::sidebar-item>
@if ($sidebar_user->can('updateNotifications', $sidebar_user))
    <x-fruit::sidebar-item :href="route('users.notifications', ['id' => $sidebar_user->id])" :current="$settings_own && $settings_route == 'users.notifications'"><x-slot:icon><x-icon.bell class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Notifications') }}</x-fruit::sidebar-item>
@endif
<x-fruit::sidebar-item :href="route('users.api_keys', ['id' => $sidebar_user->id])" :current="$settings_own && $settings_route == 'users.api_keys'"><x-slot:icon><x-icon.key-round class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('API Keys') }}</x-fruit::sidebar-item>

@if ($sidebar_user->isAdmin())
    @php
        $settings_icons = ['general' => 'settings', 'emails' => 'mail', 'alerts' => 'bell-ring', 'ai' => 'sparkles', 'api' => 'webhook', 'branding' => 'palette'];
    @endphp
    <p class="f-sidebar__heading">{{ __('Settings') }}</p>
    @foreach (app(App\Http\Controllers\SettingsController::class)->getSections() as $settings_name => $settings_info)
        <x-fruit::sidebar-item :href="route('settings', ['section' => $settings_name])" :current="$settings_route == 'settings' && $settings_section == $settings_name || ($settings_route == 'settings.ai_documents' && $settings_name == 'ai')"><x-slot:icon><x-dynamic-component :component="'icon.'.($settings_icons[$settings_name] ?? 'settings')" class="f-icon" aria-hidden="true" /></x-slot:icon>{{ $settings_info['title'] }}</x-fruit::sidebar-item>
    @endforeach
@endif

@if ($sidebar_user->isAdmin()
    || $sidebar_user->hasPermission(App\User::PERM_EDIT_USERS)
    || $sidebar_user->can('viewMailboxMenu', $sidebar_user)
    || Eventy::filter('menu.manage.can_view', false)
)
    <p class="f-sidebar__heading">{{ __('Manage') }}</p>
    @if ($sidebar_user->can('viewMailboxMenu', $sidebar_user))
        <x-fruit::sidebar-item :href="route('mailboxes')" :current="in_array($settings_route, ['mailboxes', 'mailboxes.create'])"><x-slot:icon><x-icon.inbox class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Mailboxes') }}</x-fruit::sidebar-item>
    @endif
    @if (!empty($mailbox) && $mailbox instanceof App\Mailbox && $mailbox->id && str_starts_with((string) $settings_route, 'mailboxes.'))
        {{-- The mailbox's settings pages (mailboxes/settings_menu, modules' too), as sidebar items. --}}
        <details class="f-sidebar__group app-sidebar__mailbox-pages" open>
            <summary class="f-sidebar__item"><x-icon.mail class="f-icon" aria-hidden="true" /><span class="f-sidebar__identity">{{ $mailbox->name }}</span><svg class="f-icon f-sidebar__chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg></summary>
            {!! preg_replace('#<a (?![^>]*\bclass=)#', '<a class="f-sidebar__item" ', view('mailboxes/settings_menu', ['mailbox' => $mailbox])->render()) !!}
        </details>
    @endif
    <ul class="app-sidebar__module-items">@action('menu.manage.after_mailboxes')</ul>
    @if ($sidebar_user->isAdmin() || $sidebar_user->hasPermission(App\User::PERM_EDIT_USERS))
        <x-fruit::sidebar-item :href="route('users')" :current="\App\Misc\Helper::isMenuSelected('users')"><x-slot:icon><x-icon.users class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Users') }}</x-fruit::sidebar-item>
    @endif
    @if ($sidebar_user->isAdmin())
        <x-fruit::sidebar-item :href="route('workflows')" :current="\App\Misc\Helper::isMenuSelected('workflows')"><x-slot:icon><x-icon.shuffle class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Workflows') }}</x-fruit::sidebar-item>
        <x-fruit::sidebar-item :href="route('modules')" :current="$settings_route == 'modules'"><x-slot:icon><x-icon.puzzle class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Modules') }}</x-fruit::sidebar-item>
        <x-fruit::sidebar-item :href="route('logs')" :current="\App\Misc\Helper::isMenuSelected('logs')"><x-slot:icon><x-icon.file-text class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('Logs') }}</x-fruit::sidebar-item>
        <x-fruit::sidebar-item :href="route('system')" :current="\App\Misc\Helper::isMenuSelected('system')"><x-slot:icon><x-icon.server class="f-icon" aria-hidden="true" /></x-slot:icon>{{ __('System') }}</x-fruit::sidebar-item>
    @endif
    <ul class="app-sidebar__module-items">@action('menu.manage.append')</ul>
@endif
