{{-- A user's profile (App\Livewire\UserProfile): every change is saved as it's made, text when
     leaving the field. --}}
<div class="user-profile">
    @if (auth()->user()->isAdmin() || $user->invite_state == App\User::INVITE_STATE_SENT || $user->invite_state == App\User::INVITE_STATE_NOT_INVITED)
        <x-fruit::form-section :title="__('Account')" :level="3">
            @if ($user->invite_state == App\User::INVITE_STATE_SENT || $user->invite_state == App\User::INVITE_STATE_NOT_INVITED)
                <div class="f-form-row">
                    <span class="f-label">{{ __('Status') }}</span>
                    <x-fruit::badge>{{ $user->getInviteStateName() }}</x-fruit::badge>
                </div>
            @endif

            @if (auth()->user()->isAdmin())
                <x-fruit::field :label="__('Role')" layout="row">
                    <x-fruit::select id="role" wire:model.live="role" required>
                        <option value="{{ App\User::ROLE_USER }}">{{ __('User') }}</option>
                        <option value="{{ App\User::ROLE_ADMIN }}">{{ __('Administrator') }}</option>
                    </x-fruit::select>
                    <x-slot:description>{!! __('<strong>Administrators</strong> can create new users and have access to all mailboxes and settings') !!}<br>{!! __('<strong>Users</strong> have access to the mailbox(es) specified in their permissions') !!}</x-slot:description>
                </x-fruit::field>
            @endif

            @if (auth()->user()->isAdmin() && $user->invite_state == App\User::INVITE_STATE_ACTIVATED)
                <x-fruit::field :label="__('Disabled')" :description="__('Prevent user from logging in')" layout="row">
                    <x-fruit::switch id="user_disabled" wire:model.live="disabled" />
                </x-fruit::field>
            @endif

            @if (!$user->isAdmin() && auth()->user()->isAdmin())
                <x-fruit::field :label="$user->getUserPermissionName(App\User::PERM_ONLY_ASSIGNED_TICKETS)" layout="row">
                    <x-fruit::switch id="only_assigned_tickets" wire:model.live="only_assigned" />
                </x-fruit::field>
            @endif

            @if (auth()->user()->isAdmin())
                <x-fruit::field :label="__('AI Drafts Per Day')" :description="__('Leave blank to use the limit in the AI settings. 0 turns drafting off.')" layout="row">
                    <x-fruit::number id="ai_drafts_per_day" wire:model.live.blur="ai_drafts_per_day" min="0" max="10000" :placeholder="App\Ai\Settings::draftsPerDay(null)" />
                </x-fruit::field>
            @endif
        </x-fruit::form-section>
    @endif

    <x-fruit::form-section :title="__('Profile')" :level="3">
        @action('user.edit.before_first_name', $user)

        <x-fruit::field :label="__('First Name')" layout="row">
            <x-fruit::input id="first_name" wire:model.live.blur="first_name" maxlength="20" required />
        </x-fruit::field>

        <x-fruit::field :label="__('Last Name')" layout="row">
            <x-fruit::input id="last_name" wire:model.live.blur="last_name" maxlength="30" />
        </x-fruit::field>

        @action('user.edit.before_email', $user)

        <x-fruit::field :label="__('Email')" layout="row">
            <x-fruit::input type="email" id="email" wire:model.live.blur="email" maxlength="100" required :readonly="!auth()->user()->isAdmin()" />
        </x-fruit::field>

        <x-fruit::field :label="__('Alternate Emails')" :description="__('Comma separated list of email addresses from which user can reply to email notifications in addition to user\'s main Email')" layout="row">
            <x-fruit::input id="emails" wire:model.live.blur="emails" :placeholder="__('(optional)')" />
        </x-fruit::field>

        @if ($user->id == auth()->id())
            <div class="f-form-row">
                <span class="f-label">{{ __('Password') }}</span>
                <a href="{{ route('users.password', ['id' => $user->id]) }}">{{ __('Change your password') }}</a>
            </div>
        @endif

        <x-fruit::field :label="__('Job Title')" layout="row">
            <x-fruit::input id="job_title" wire:model.live.blur="job_title" :placeholder="__('(optional)')" maxlength="100" />
        </x-fruit::field>

        <x-fruit::field :label="__('Phone Number')" layout="row">
            <x-fruit::input id="phone" wire:model.live.blur="phone" :placeholder="__('(optional)')" maxlength="60" />
        </x-fruit::field>
        @action('user.edit.phone_append', $user)

        @action('user.edit.before_photo', $user)

        @if ($user->photo_url)
            <div id="user-profile-photo" class="f-form-row">
                <span>{{ __('Photo') }}</span>
                <span class="f-row">
                    <x-fruit::avatar :src="$user->getPhotoUrl()" :label="__('Photo')" />
                    <x-fruit::button variant="ghost" size="small" id="user-photo-delete" x-on:click="Tallport.confirm({ message: {{ \Illuminate\Support\Js::from(__('Delete this photo?')) }}, confirm: {{ \Illuminate\Support\Js::from(__('Delete Photo')) }}, tone: 'danger' }).then(ok => ok && $wire.deletePhoto())">{{ __('Delete Photo') }}</x-fruit::button>
                </span>
            </div>
        @endif
        <x-fruit::field :label="$user->photo_url ? __('Replace Photo') : __('Photo')" :description="__('Image will be re-sized to :dimensions. JPG, GIF, PNG accepted.', ['dimensions' => config('app.user_photo_size').'x'.config('app.user_photo_size')])" layout="row">
            <x-fruit::file id="photo_url" wire:model="photo" accept="image/jpeg,image/png,image/gif" />
        </x-fruit::field>
    </x-fruit::form-section>
</div>
