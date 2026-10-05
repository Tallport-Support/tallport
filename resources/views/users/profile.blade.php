@extends('layouts.app')

@section('page_width', 'narrow')

@section('title_full', __('Edit User').' - '.$user->getFullName())

@section('body_attrs')@parent data-user_id="{{ $user->id }}"@endsection

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content" x-data="tallportUserProfile">
        @include('partials/flash_messages')

        <form id="page-form" class="settings-form" method="POST" action="" enctype="multipart/form-data">
            {{ csrf_field() }}

            @if (auth()->user()->isAdmin() || $user->invite_state == App\User::INVITE_STATE_SENT || $user->invite_state == App\User::INVITE_STATE_NOT_INVITED)
                <x-fruit::form-section :title="__('Account')">
                    @if ($user->invite_state == App\User::INVITE_STATE_SENT || $user->invite_state == App\User::INVITE_STATE_NOT_INVITED)
                        <div class="f-form-row">
                            <span class="f-label">{{ __('Status') }}</span>
                            <x-fruit::badge>{{ $user->getInviteStateName() }}</x-fruit::badge>
                        </div>
                    @endif

                    @if (auth()->user()->isAdmin())
                        <x-fruit::field :label="__('Role')" layout="row">
                            <x-fruit::select id="role" name="role" required autofocus>
                                <option value="{{ App\User::ROLE_USER }}" @selected(old('role', $user->role) == App\User::ROLE_USER)>{{ __('User') }}</option>
                                <option value="{{ App\User::ROLE_ADMIN }}" @selected(old('role', $user->role) == App\User::ROLE_ADMIN)>{{ __('Administrator') }}</option>
                            </x-fruit::select>
                            <x-slot:description>{!! __('<strong>Administrators</strong> can create new users and have access to all mailboxes and settings') !!}<br>{!! __('<strong>Users</strong> have access to the mailbox(es) specified in their permissions') !!}</x-slot:description>
                        </x-fruit::field>
                    @endif

                    @if (auth()->user()->isAdmin() && $user->invite_state == App\User::INVITE_STATE_ACTIVATED)
                        <x-fruit::field :label="__('Disabled')" :description="__('Prevent user from logging in')" layout="row">
                            <x-fruit::switch id="user_disabled" name="disabled" :value="App\User::STATUS_DISABLED" :checked="old('disabled', $user->status) == App\User::STATUS_DISABLED" />
                        </x-fruit::field>
                    @endif

                    @if (!$user->isAdmin() && Auth::user()->isAdmin())
                        <x-fruit::checkbox id="only_assigned_tickets" name="only_assigned_tickets" value="1" :checked="(bool) old('only_assigned_tickets', $user->hasPermission(\App\User::PERM_ONLY_ASSIGNED_TICKETS))">{{ $user->getUserPermissionName(\App\User::PERM_ONLY_ASSIGNED_TICKETS) }}</x-fruit::checkbox>
                    @endif
                </x-fruit::form-section>
            @endif

            <x-fruit::form-section :title="__('Profile')">
                @action('user.edit.before_first_name', $user)

                <x-fruit::field :label="__('First Name')" layout="row">
                    <x-fruit::input id="first_name" name="first_name" :value="old('first_name', $user->first_name)" maxlength="20" required autofocus />
                </x-fruit::field>

                <x-fruit::field :label="__('Last Name')" layout="row">
                    <x-fruit::input id="last_name" name="last_name" :value="old('last_name', $user->last_name)" maxlength="30" />
                </x-fruit::field>

                @action('user.edit.before_email', $user)

                <x-fruit::field :label="__('Email')" layout="row">
                    <x-fruit::input type="email" id="email" name="email" :value="old('email', $user->email)" maxlength="100" required :readonly="!auth()->user()->isAdmin()" />
                </x-fruit::field>

                <x-fruit::field :label="__('Alternate Emails')" :description="__('Comma separated list of email addresses from which user can reply to email notifications in addition to user\'s main Email')" layout="row">
                    <x-fruit::input id="emails" name="emails" :value="old('emails', $user->emails)" :placeholder="__('(optional)')" />
                </x-fruit::field>

                @if ($user->id == Auth::user()->id)
                    <div class="f-form-row">
                        <span class="f-label">{{ __('Password') }}</span>
                        <a href="{{ route('users.password', ['id' => $user->id]) }}">{{ __('Change your password') }}</a>
                    </div>
                @endif

                <x-fruit::field :label="__('Job Title')" layout="row">
                    <x-fruit::input id="job_title" name="job_title" :value="old('job_title', $user->job_title)" :placeholder="__('(optional)')" maxlength="100" />
                </x-fruit::field>

                <x-fruit::field :label="__('Phone Number')" layout="row">
                    <x-fruit::input id="phone" name="phone" :value="old('phone', $user->phone)" :placeholder="__('(optional)')" maxlength="60" />
                </x-fruit::field>
                @action('user.edit.phone_append', $user)

                @action('user.edit.before_photo', $user)

                @if ($user->photo_url)
                    {{-- The photo on the right with its Delete (a row stretches its first item). --}}
                    <div id="user-profile-photo" class="f-form-row">
                        <span>{{ __('Photo') }}</span>
                        <span class="f-row">
                            <x-fruit::avatar :src="$user->getPhotoUrl()" :label="__('Photo')" />
                            <button type="button" id="user-photo-delete" class="f-button f-button--ghost f-button--small" @click="deletePhoto($el)">{{ __('Delete Photo') }}</button>
                        </span>
                    </div>
                @endif
                <x-fruit::field :label="$user->photo_url ? __('Replace Photo') : __('Photo')" :description="__('Image will be re-sized to :dimensions. JPG, GIF, PNG accepted.', ['dimensions' => config('app.user_photo_size').'x'.config('app.user_photo_size')])" layout="row">
                    <x-fruit::file id="photo_url" name="photo_url" />
                </x-fruit::field>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('General')">
                <x-fruit::field :label="__('Language')" layout="row">
                    <x-fruit::select id="locale" name="locale">
                        @include('partials/locale_options', ['selected' => old('locale', $user->getLocale())])
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::field :label="__('Timezone')" layout="row">
                    <x-fruit::select id="timezone" name="timezone" required>
                        @include('partials/timezone_options', ['current_timezone' => old('timezone', $user->timezone)])
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::fieldset>
                    <legend>{{ __('Time Format') }}</legend>
                    <x-fruit::radio id="12hour" name="time_format" :value="App\User::TIME_FORMAT_12" :checked="old('time_format', $user->time_format) == App\User::TIME_FORMAT_12">{{ __('12-hour clock (e.g. 2:13pm)') }}</x-fruit::radio>
                    <x-fruit::radio id="24hour" name="time_format" :value="App\User::TIME_FORMAT_24" :checked="old('time_format', $user->time_format) == App\User::TIME_FORMAT_24 || !$user->time_format">{{ __('24-hour clock (e.g. 14:13)') }}</x-fruit::radio>
                </x-fruit::fieldset>

                <x-fruit::field :label="__('Keyboard Shortcuts')" :description="__('On (press ? to see them)')" layout="row">
                    <input type="hidden" name="keyboard_shortcuts_shown" value="1">
                    <x-fruit::switch id="keyboard_shortcuts" name="keyboard_shortcuts" value="1" :checked="$user->hasKeyboardShortcuts()" />
                </x-fruit::field>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('AI Assistant')">
                <x-fruit::field :label="__('AI Language')" :description="__('The language of AI summaries and translations.')" layout="row">
                    <x-fruit::select id="ai_language" name="ai_language">
                        <option value="">{{ __('Default') }}</option>
                        @foreach (App\Ai\Settings::displayNames() as $code => $name)
                            <option value="{{ $code }}" @selected(old('ai_language', $user->ai_language) == $code)>{{ App\Ai\Settings::optionName($code) }}</option>
                        @endforeach
                    </x-fruit::select>
                </x-fruit::field>

                @if (Auth::user()->isAdmin())
                    <x-fruit::field :label="__('AI Drafts Per Day')" :description="__('Leave blank to use the limit in the AI Assistant settings. 0 turns drafting off.')" layout="row">
                        <x-fruit::number id="ai_drafts_per_day" name="ai_drafts_per_day" :value="old('ai_drafts_per_day', $user->ai_drafts_per_day)" min="0" max="10000" :placeholder="App\Ai\Settings::draftsPerDay(null)" />
                    </x-fruit::field>
                @endif
            </x-fruit::form-section>

            @if (Auth::user()->can('delete', $user))
                <x-fruit::form-section :title="__('Danger Zone')">
                    <div class="f-form-row">
                        <button type="button" id="delete-user-trigger" class="f-button f-button--danger" @click="$dispatch('fruit-dialog-open', { name: 'delete-user' })">{{ __('Delete User') }}</button>
                    </div>
                </x-fruit::form-section>
            @endif

            <div class="f-row">
            @if (Auth::user()->isAdmin())
                @if ($user->invite_state == App\User::INVITE_STATE_ACTIVATED)
                    @if ($user->id != Auth::user()->id)
                        <button type="button" class="f-button f-button--ghost reset-password-trigger" @click="resetPassword($el)">{{ __('Reset Password') }}</button>
                    @endif
                @elseif ($user->invite_state == App\User::INVITE_STATE_SENT)
                    <button type="button" class="f-button f-button--ghost resend-invite-trigger" @click="sendInvite($el, true)">{{ __('Re-send Invite Email') }}</button>
                @elseif ($user->invite_state == App\User::INVITE_STATE_NOT_INVITED)
                    <button type="button" class="f-button f-button--ghost send-invite-trigger" @click="sendInvite($el, false)">{{ __('Send Invite Email') }}</button>
                @endif
            @endif
            </div>
        </form>

        @if (Auth::user()->can('delete', $user))
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
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save Profile') }}</x-fruit::button>
@endsection
