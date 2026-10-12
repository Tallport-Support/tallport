{{-- A user's profile (App\Livewire\UserProfile), the level's page; every change is saved as it's made,
     text when leaving the field. own: what one sets for oneself (/profile). manage: an account in
     Settings › Users › the person: who they are, the account, their other pages, then its actions. --}}
<x-fruit::page width="narrow" x-data="tallportUserProfile" data-user_id="{{ $user->id }}">
@php $oneself = $this->isOneself(); @endphp
@if ($mode == 'own')
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
            <x-fruit::input type="email" id="email" :value="$user->email" readonly />
        </x-fruit::field>

        <x-fruit::field :label="__('Alternate Emails')" :description="__('Comma separated list of email addresses from which user can reply to email notifications in addition to user\'s main Email')" layout="row">
            <x-fruit::input id="emails" wire:model.live.blur="emails" :placeholder="__('(optional)')" />
        </x-fruit::field>

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

    <x-fruit::form-section>
        <a class="f-form-row f-form-row--link" href="{{ route('own.password') }}" wire:navigate @if ($section == 'password') aria-current="page" @endif><span>{{ __('Change your password') }}</span></a>
    </x-fruit::form-section>
@else
    <x-fruit::form-section>
        <div class="f-form-row">
            <span class="users-row__person">
                @if ($user->photo_url)
                    <x-fruit::avatar :src="$user->getPhotoUrl()" />
                @else
                    <x-fruit::avatar>{{ mb_strtoupper(mb_substr((string) $user->first_name, 0, 1).mb_substr((string) $user->last_name, 0, 1)) }}</x-fruit::avatar>
                @endif
                <span class="users-row__name"><span>{{ $user->getFullName() }}@if ($oneself) <span class="f-muted">{{ __('(You)') }}</span>@endif</span><small class="f-help">{{ $user->email }}</small></span>
            </span>
        </div>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Account')" :level="3">
        @if ($user->invite_state == App\User::INVITE_STATE_SENT || $user->invite_state == App\User::INVITE_STATE_NOT_INVITED)
            <div class="f-form-row">
                <span class="f-label">{{ __('Status') }}</span>
                <x-fruit::badge>{{ $user->getInviteStateName() }}</x-fruit::badge>
            </div>
        @endif

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

        @if (auth()->user()->isAdmin())
            @if ($oneself)
                <x-fruit::field :label="__('Role')" layout="row" :description="__('You can\'t change your own role.')">
                    <x-fruit::select id="role" wire:model.live="role" disabled>
                        <option value="{{ App\User::ROLE_USER }}">{{ __('User') }}</option>
                        <option value="{{ App\User::ROLE_ADMIN }}">{{ __('Administrator') }}</option>
                    </x-fruit::select>
                </x-fruit::field>
            @else
                <x-fruit::field :label="__('Role')" layout="row">
                    <x-fruit::select id="role" wire:model.live="role" required>
                        <option value="{{ App\User::ROLE_USER }}">{{ __('User') }}</option>
                        <option value="{{ App\User::ROLE_ADMIN }}">{{ __('Administrator') }}</option>
                    </x-fruit::select>
                    <x-slot:description>{!! __('<strong>Administrators</strong> can create new users and have access to all mailboxes and settings') !!}<br>{!! __('<strong>Users</strong> have access to the mailbox(es) specified in their permissions') !!}</x-slot:description>
                </x-fruit::field>
            @endif

            @if ($user->invite_state == App\User::INVITE_STATE_ACTIVATED)
                <x-fruit::field :label="__('Disabled')" :description="$oneself ? __('You can\'t turn off your own sign-in.') : __('Prevent user from logging in')" layout="row">
                    <x-fruit::switch id="user_disabled" wire:model.live="disabled" :disabled="$oneself" />
                </x-fruit::field>
            @endif

            @if (!$user->isAdmin())
                <x-fruit::field :label="$user->getUserPermissionName(App\User::PERM_ONLY_ASSIGNED_TICKETS)" layout="row">
                    <x-fruit::switch id="only_assigned_tickets" wire:model.live="only_assigned" />
                </x-fruit::field>
            @endif

            <x-fruit::field :label="__('AI Drafts Per Day')" :description="__('Leave blank to use the limit in the AI settings. 0 turns drafting off.')" layout="row">
                <x-fruit::number id="ai_drafts_per_day" wire:model.live.blur="ai_drafts_per_day" min="0" max="10000" :placeholder="App\Ai\Settings::draftsPerDay(null)" />
            </x-fruit::field>
        @endif
    </x-fruit::form-section>

    <x-fruit::form-section :footer="$oneself ? __('What you set for yourself is under Profile and Notifications, as it is for everyone.') : null">
        @action('user.profile.menu.after_profile', $user)
        @if (auth()->user()->isAdmin())
            <a class="f-form-row f-form-row--link" href="{{ route('users.permissions', ['id' => $user->id]) }}" wire:navigate @if ($section == 'permissions') aria-current="page" @endif><span>{{ __('Permissions') }}</span><span class="f-form-row__value">{{ $user->isAdmin() ? __('All Mailboxes') : trans_choice('1 mailbox|:count mailboxes', $user->mailboxes()->count()) }}</span></a>
        @endif
        @if ($oneself)
            <a class="f-form-row f-form-row--link" href="{{ route('own.profile') }}" wire:navigate><span>{{ __('Your Profile') }}</span><span class="f-form-row__value">{{ __('Password, two-factor, notifications') }}</span></a>
        @elseif (auth()->user()->isAdmin())
            <a class="f-form-row f-form-row--link" href="{{ route('users.security', ['id' => $user->id]) }}" wire:navigate @if ($section == 'security') aria-current="page" @endif><span>{{ __('Security') }}</span></a>
        @endif
    </x-fruit::form-section>

    {{-- Sign-in: a new password, or the invite. --}}
    @if (auth()->user()->isAdmin() && !$oneself)
        <x-fruit::form-section>
            <div class="f-form-row">
                @if ($user->invite_state == App\User::INVITE_STATE_ACTIVATED)
                    <button type="button" class="f-button f-button--ghost reset-password-trigger" @click="resetPassword($el)">{{ __('Reset Password') }}</button>
                @elseif ($user->invite_state == App\User::INVITE_STATE_SENT)
                    <button type="button" class="f-button f-button--ghost resend-invite-trigger" @click="sendInvite($el, true)">{{ __('Re-send Invite Email') }}</button>
                @else
                    <button type="button" class="f-button f-button--ghost send-invite-trigger" @click="sendInvite($el, false)">{{ __('Send Invite Email') }}</button>
                @endif
            </div>
        </x-fruit::form-section>
    @endif

    @if (auth()->user()->isAdmin())
        <x-fruit::form-section :title="__('Danger Zone')" :level="3">
            <div class="f-form-row">
                @if ($oneself)
                    <div>
                        <button type="button" id="delete-user-trigger" class="f-button f-button--danger" disabled aria-describedby="delete-user-oneself">{{ __('Delete User') }}</button>
                        <p class="f-help" id="delete-user-oneself">{{ __('You can\'t remove yourself.') }}</p>
                    </div>
                @else
                    <button type="button" id="delete-user-trigger" class="f-button f-button--danger" @click="$dispatch('fruit-dialog-open', { name: 'delete-user' })">{{ __('Delete User') }}</button>
                @endif
            </div>
        </x-fruit::form-section>

        @unless ($oneself)
            <x-fruit::dialog name="delete-user" aria-labelledby="delete-user-title">
                <form x-data="{ typed: '' }" @submit.prevent="deleteUser($el)">
                    <header class="f-dialog__header"><h2 id="delete-user-title">{{ __('Delete User') }}</h2></header>
                    <div class="f-dialog__body f-stack">
                        <p>{!! __h("Deleting :name will deactivate workflows they are tied to and assign their conversations to:", ['name' => '<strong>'.htmlspecialchars($user->getFullName()).'</strong>']) !!}</p>
                        @foreach (App\Mailbox::all() as $assign_mailbox)
                            <x-fruit::field :label="$assign_mailbox->name" layout="row">
                                <x-fruit::select name="assign_user[{{ $assign_mailbox->id }}]">
                                    <option value="-1">{{ __("Anyone") }}</option>
                                    @foreach ($assign_mailbox->usersHavingAccess() as $assign_user)
                                        @if ($assign_user->id != $user->id)
                                            <option value="{{ $assign_user->id }}">{{ $assign_user->getFullName() }}</option>
                                        @endif
                                    @endforeach
                                </x-fruit::select>
                            </x-fruit::field>
                        @endforeach
                        <p>{!! __h("If you are sure, type :delete and click the red button.", ['delete' => '<strong>DELETE</strong>']) !!}</p>
                        <x-fruit::input x-model="typed" autocomplete="off" placeholder="{!! __h('Type :delete', ['delete' => '&quot;DELETE&quot;']) !!}" aria-label="{!! __h('Type :delete', ['delete' => '&quot;DELETE&quot;']) !!}" />
                    </div>
                    <footer class="f-dialog__footer">
                        <x-fruit::button type="button" @click="$dispatch('fruit-dialog-close', { name: 'delete-user' })">{{ __('Cancel') }}</x-fruit::button>
                        <x-fruit::button type="submit" variant="danger" x-bind:disabled="typed != 'DELETE'">{{ __('Delete User') }}</x-fruit::button>
                    </footer>
                </form>
            </x-fruit::dialog>
        @endunless
    @endif
@endif
</x-fruit::page>
