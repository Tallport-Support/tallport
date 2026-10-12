{{-- A person as a drill-down level (layouts/app): their profile (saved as it's changed), a row for
     each further page with its current value ($user_section: the open one's row is current), and
     the account's actions. --}}
<x-fruit::drilldown-column :title="$user->getFullName()" id="user">
    <x-slot:back><x-fruit::back-link :href="route('users')" wire:navigate>{{ __('Users') }}</x-fruit::back-link></x-slot:back>
    <x-fruit::page width="narrow" x-data="tallportUserProfile" data-user_id="{{ $user->id }}">
        <livewire:user-profile :user="$user" :wire:key="'user-profile-'.$user->id" />

        <x-fruit::form-section>
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

        {{-- Sign-in: a new password, or the invite. --}}
        @if (Auth::user()->isAdmin() && $user->id != Auth::user()->id)
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

        @if (Auth::user()->can('delete', $user))
            <x-fruit::form-section :title="__('Danger Zone')" :level="3">
                <div class="f-form-row">
                    <button type="button" id="delete-user-trigger" class="f-button f-button--danger" @click="$dispatch('fruit-dialog-open', { name: 'delete-user' })">{{ __('Delete User') }}</button>
                </div>
            </x-fruit::form-section>

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
        @endif
    </x-fruit::page>
</x-fruit::drilldown-column>
