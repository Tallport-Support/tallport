{{-- A person as a drill-down level (layouts/app): who they are, and a row for each of their pages with
     its current value; $user_section is the open page's row (current), if any. --}}
<x-fruit::drilldown-column :title="$user->getFullName()" id="user">
    <x-slot:back><x-fruit::back-link :href="route('users')" wire:navigate>{{ __('Users') }}</x-fruit::back-link></x-slot:back>
    <x-fruit::page width="narrow" style="--f-page-background: var(--f-grouped-background)">
        <x-fruit::form-section>
            <div class="f-form-row">
                <span class="users-row__person">
                    @if ($user->photo_url)
                        <x-fruit::avatar :src="$user->getPhotoUrl()" />
                    @else
                        <x-fruit::avatar>{{ mb_strtoupper(mb_substr((string) $user->first_name, 0, 1).mb_substr((string) $user->last_name, 0, 1)) }}</x-fruit::avatar>
                    @endif
                    <span class="users-row__name">{{ $user->getFullName() }}<small class="f-help">{{ $user->email }}</small></span>
                </span>
            </div>
        </x-fruit::form-section>
        <x-fruit::form-section>
            <a class="f-form-row f-form-row--link" href="{{ route('users.profile', ['id' => $user->id]) }}" wire:navigate @if (($user_section ?? null) == 'profile') aria-current="page" @endif><span>{{ __('Profile') }}</span></a>
            @action('user.profile.menu.after_profile', $user)
            @if (Auth::user()->id == $user->id || Auth::user()->isAdmin())
                <a class="f-form-row f-form-row--link" href="{{ route('users.security', ['id' => $user->id]) }}" wire:navigate @if (($user_section ?? null) == 'security') aria-current="page" @endif><span>{{ __('Security') }}</span></a>
            @endif
            @if (Auth::user()->isAdmin())
                <a class="f-form-row f-form-row--link" href="{{ route('users.permissions', ['id' => $user->id]) }}" wire:navigate @if (($user_section ?? null) == 'permissions') aria-current="page" @endif><span>{{ __('Permissions') }}</span><span class="f-form-row__value">{{ $user->isAdmin() ? __('All Mailboxes') : trans_choice('1 mailbox|:count mailboxes', $user->mailboxes()->count()) }}</span></a>
            @endif
            @if (Auth::user()->can('updateNotifications', $user))
                <a class="f-form-row f-form-row--link" href="{{ route('users.notifications', ['id' => $user->id]) }}" wire:navigate @if (($user_section ?? null) == 'notifications') aria-current="page" @endif><span>{{ __('Notifications') }}</span></a>
            @endif
        </x-fruit::form-section>
    </x-fruit::page>
</x-fruit::drilldown-column>
