{{-- A user's pages: the user (to switch), the pages, and a new user. The user's own pages are
     each on their own (the account menu leads to them), titled by the page. --}}
@if ($user->id == Auth::user()->id)
<x-page-nav :label="__('User')">
    <x-slot:title><h1>{{ [
        'users.profile' => __('Profile'),
        'users.security' => __('Security'),
        'users.preferences' => __('Preferences'),
        'users.api_keys' => __('API Keys'),
        'users.permissions' => __('Permissions'),
        'users.notifications' => __('Notifications'),
        'users.password' => __('Change your password'),
    ][Route::currentRouteName()] ?? $user->getFullName() }}</h1></x-slot:title>
</x-page-nav>
@else
<x-page-nav :label="__('User')">
    <x-slot:title>
        @if (isset($users) && count($users))
            <x-fruit::menu :title="$user->getFullName()" class="page-nav__switcher">
                @foreach ($users as $user_item)
                    <x-fruit::menu-link :href="route('users.profile', ['id' => $user_item->id])" :aria-current="$user_item->id == $user->id ? 'page' : null">{{ $user_item->getFullName() }}@if ($user_item->invite_state == App\User::INVITE_STATE_SENT) <small class="f-muted">({{ __('Invited') }})</small>@elseif ($user_item->invite_state == App\User::INVITE_STATE_NOT_INVITED) <small class="f-muted">({{ __('Not Invited') }})</small>@endif</x-fruit::menu-link>
                @endforeach
            </x-fruit::menu>
        @else
            <h1>{{ $user->getFullName() }}</h1>
        @endif
    </x-slot:title>
    @if (Auth::user()->isAdmin() || Auth::user()->hasPermission(App\User::PERM_EDIT_USERS))
        <x-slot:actions>
            <a href="{{ route('users.create') }}" class="f-button">{{ __('New User') }}</a>
        </x-slot:actions>
    @endif
    <a href="{{ route('users.profile', ['id' => $user->id]) }}" @if (Route::currentRouteName() == 'users.profile') aria-current="page" @endif>{{ __('Profile') }}</a>
    @action('user.profile.menu.after_profile', $user)
    @if (Auth::user()->id == $user->id || Auth::user()->isAdmin())
        <a href="{{ route('users.security', ['id' => $user->id]) }}" @if (Route::currentRouteName() == 'users.security') aria-current="page" @endif>{{ __('Security') }}</a>
    @endif
    @if (Auth::user()->id == $user->id)
        <a href="{{ route('users.preferences', ['id' => $user->id]) }}" @if (Route::currentRouteName() == 'users.preferences') aria-current="page" @endif>{{ __('Preferences') }}</a>
    @endif
    @if (Auth::user()->id == $user->id)
        <a href="{{ route('users.api_keys', ['id' => $user->id]) }}" @if (Route::currentRouteName() == 'users.api_keys') aria-current="page" @endif>{{ __('API Keys') }}</a>
    @endif
    @if (Auth::user()->isAdmin())
        <a href="{{ route('users.permissions', ['id' => $user->id]) }}" @if (Route::currentRouteName() == 'users.permissions') aria-current="page" @endif>{{ __('Permissions') }}</a>
    @endif
    @if (Auth::user()->can('updateNotifications', $user))
        <a href="{{ route('users.notifications', ['id' => $user->id]) }}" @if (Route::currentRouteName() == 'users.notifications') aria-current="page" @endif>{{ __('Notifications') }}</a>
    @endif
</x-page-nav>
@endif
