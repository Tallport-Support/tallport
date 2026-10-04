@extends('layouts.app')

@section('title_full', __('Edit User').' - '.$user->getFullName())

@section('body_attrs')@parent data-user_id="{{ $user->id }}"@endsection

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form class="settings-form" method="POST" action="" enctype="multipart/form-data">
            {{ csrf_field() }}

            @if ($user->invite_state == App\User::INVITE_STATE_SENT || $user->invite_state == App\User::INVITE_STATE_NOT_INVITED)
                <div><x-fruit::badge>{{ $user->getInviteStateName() }}</x-fruit::badge></div>
            @endif

            @if (auth()->user()->isAdmin())
                <x-fruit::field :label="__('Role')">
                    <x-fruit::select id="role" name="role" required autofocus>
                        <option value="{{ App\User::ROLE_USER }}" @selected(old('role', $user->role) == App\User::ROLE_USER)>{{ __('User') }}</option>
                        <option value="{{ App\User::ROLE_ADMIN }}" @selected(old('role', $user->role) == App\User::ROLE_ADMIN)>{{ __('Administrator') }}</option>
                    </x-fruit::select>
                </x-fruit::field>
                <p class="f-help settings-form__note">{!! __('<strong>Administrators</strong> can create new users and have access to all mailboxes and settings') !!}<br>{!! __('<strong>Users</strong> have access to the mailbox(es) specified in their permissions') !!}</p>
            @endif

            @if (auth()->user()->isAdmin() && $user->invite_state == App\User::INVITE_STATE_ACTIVATED)
                <x-fruit::switch id="user_disabled" name="disabled" :value="App\User::STATUS_DISABLED" :checked="old('disabled', $user->status) == App\User::STATUS_DISABLED" :description="__('Prevent user from logging in')">{{ __('Disabled') }}</x-fruit::switch>
            @endif

            @action('user.edit.before_first_name', $user)

            <x-fruit::field :label="__('First Name')">
                <x-fruit::input id="first_name" name="first_name" :value="old('first_name', $user->first_name)" maxlength="20" required autofocus />
            </x-fruit::field>

            <x-fruit::field :label="__('Last Name')">
                <x-fruit::input id="last_name" name="last_name" :value="old('last_name', $user->last_name)" maxlength="30" />
            </x-fruit::field>

            @action('user.edit.before_email', $user)

            <x-fruit::field :label="__('Email')">
                <x-fruit::input type="email" id="email" name="email" :value="old('email', $user->email)" maxlength="100" required :readonly="!auth()->user()->isAdmin()" />
            </x-fruit::field>

            <x-fruit::field :label="__('Alternate Emails')" :description="__('Comma separated list of email addresses from which user can reply to email notifications in addition to user\'s main Email')">
                <x-fruit::input id="emails" name="emails" :value="old('emails', $user->emails)" :placeholder="__('(optional)')" />
            </x-fruit::field>

            @if ($user->id == Auth::user()->id)
                <div class="f-field">
                    <span class="f-label">{{ __('Password') }}</span>
                    <a href="{{ route('users.password', ['id' => $user->id]) }}">{{ __('Change your password') }}</a>
                </div>
            @endif

            <x-fruit::field :label="__('Job Title')">
                <x-fruit::input id="job_title" name="job_title" :value="old('job_title', $user->job_title)" :placeholder="__('(optional)')" maxlength="100" />
            </x-fruit::field>

            <x-fruit::field :label="__('Phone Number')">
                <x-fruit::input id="phone" name="phone" :value="old('phone', $user->phone)" :placeholder="__('(optional)')" maxlength="60" />
            </x-fruit::field>
            @action('user.edit.phone_append', $user)

            <x-fruit::field :label="__('Language')">
                <x-fruit::select id="locale" name="locale">
                    @include('partials/locale_options', ['selected' => old('locale', $user->getLocale())])
                </x-fruit::select>
            </x-fruit::field>

            <x-fruit::field :label="__('AI Language')" :description="__('The language of AI summaries and translations.')">
                <x-fruit::select id="ai_language" name="ai_language">
                    <option value="">{{ __('Default') }}</option>
                    @foreach (App\Ai\Settings::displayNames() as $code => $name)
                        <option value="{{ $code }}" @selected(old('ai_language', $user->ai_language) == $code)>{{ App\Ai\Settings::optionName($code) }}</option>
                    @endforeach
                </x-fruit::select>
            </x-fruit::field>

            <x-fruit::field :label="__('Timezone')">
                <x-fruit::select id="timezone" name="timezone" required>
                    @include('partials/timezone_options', ['current_timezone' => old('timezone', $user->timezone)])
                </x-fruit::select>
            </x-fruit::field>

            <x-fruit::fieldset>
                <legend>{{ __('Time Format') }}</legend>
                <x-fruit::radio id="12hour" name="time_format" :value="App\User::TIME_FORMAT_12" :checked="old('time_format', $user->time_format) == App\User::TIME_FORMAT_12">{{ __('12-hour clock (e.g. 2:13pm)') }}</x-fruit::radio>
                <x-fruit::radio id="24hour" name="time_format" :value="App\User::TIME_FORMAT_24" :checked="old('time_format', $user->time_format) == App\User::TIME_FORMAT_24 || !$user->time_format">{{ __('24-hour clock (e.g. 14:13)') }}</x-fruit::radio>
            </x-fruit::fieldset>

            <input type="hidden" name="keyboard_shortcuts_shown" value="1">
            <x-fruit::switch id="keyboard_shortcuts" name="keyboard_shortcuts" value="1" :checked="$user->hasKeyboardShortcuts()" :description="__('On (press ? to see them)')">{{ __('Keyboard Shortcuts') }}</x-fruit::switch>

            @if (!$user->isAdmin() && Auth::user()->isAdmin())
                <x-fruit::checkbox id="only_assigned_tickets" name="only_assigned_tickets" value="1" :checked="(bool) old('only_assigned_tickets', $user->hasPermission(\App\User::PERM_ONLY_ASSIGNED_TICKETS))">{{ $user->getUserPermissionName(\App\User::PERM_ONLY_ASSIGNED_TICKETS) }}</x-fruit::checkbox>
            @endif

            @if (Auth::user()->isAdmin())
                <x-fruit::field :label="__('AI Drafts Per Day')" :description="__('Leave blank to use the limit in the AI Assistant settings. 0 turns drafting off.')">
                    <x-fruit::number id="ai_drafts_per_day" name="ai_drafts_per_day" :value="old('ai_drafts_per_day', $user->ai_drafts_per_day)" min="0" max="10000" :placeholder="App\Ai\Settings::draftsPerDay(null)" />
                </x-fruit::field>
            @endif

            @action('user.edit.before_photo', $user)

            <div class="f-stack">
                @if ($user->photo_url)
                    <div id="user-profile-photo" class="f-row">
                        <x-fruit::avatar :src="$user->getPhotoUrl()" :label="__('Photo')" />
                        <a href="#" id="user-photo-delete" data-loading-text="{{ __('Deleting') }}…">{{ __('Delete Photo') }}</a>
                    </div>
                @endif
                <x-fruit::field :label="__('Photo')" :description="__('Image will be re-sized to :dimensions. JPG, GIF, PNG accepted.', ['dimensions' => config('app.user_photo_size').'x'.config('app.user_photo_size')])">
                    <x-fruit::file id="photo_url" name="photo_url" />
                </x-fruit::field>
            </div>

            <div class="settings-form__actions f-row">
                <x-fruit::button type="submit" variant="primary">{{ __('Save Profile') }}</x-fruit::button>

                @if (Auth::user()->isAdmin())
                    @if ($user->invite_state == App\User::INVITE_STATE_ACTIVATED)
                        @if ($user->id != Auth::user()->id)
                            <a href="#" class="f-button f-button--ghost reset-password-trigger" data-loading-text="{{ __('Resetting password') }}…">{{ __('Reset password') }}</a>
                        @endif
                    @elseif ($user->invite_state == App\User::INVITE_STATE_SENT)
                        <a href="#" class="f-button f-button--ghost resend-invite-trigger" data-loading-text="{{ __('Resending') }}…">{{ __('Re-send invite email') }}</a>
                    @elseif ($user->invite_state == App\User::INVITE_STATE_NOT_INVITED)
                        <a href="#" class="f-button f-button--ghost send-invite-trigger" data-loading-text="{{ __('Sending') }}…">{{ __('Send invite email') }}</a>
                    @endif
                @endif

                @if (Auth::user()->can('delete', $user))
                    <a href="#" id="delete-user-trigger" class="f-button f-button--danger settings-form__end">{{ __('Delete user') }}</a>
                @endif
            </div>
        </form>
    </div>

    <div id="delete_user_modal" class="hidden">
        <div>
        <div class="text-center">
            <div class="col-sm-10 col-sm-offset-1 text-large margin-top-10 margin-bottom">{!! __h("Deleting :name will deactivate workflows they are tied to and assign their conversations to:", ['name' => '<strong>'.htmlspecialchars($user->getFullName()).'</strong>']) !!}</div>
            <form class="assign_form form-horizontal">
                @foreach (App\Mailbox::all() as $assign_mailbox)
                    <div class="col-sm-9 col-sm-offset-1">
                        <div class="form-group">
                            <label class="col-sm-5 control-label">{{ $assign_mailbox->name }}</label>
                            <div class="col-sm-7">
                                <select name="assign_user[{{ $assign_mailbox->id }}]" class="form-control input-sized">
                                    <option value="-1">{{ __("Anyone") }}</option>
                                    @foreach ($assign_mailbox->usersHavingAccess() as $assign_user)
                                        @if ($assign_user->id != $user->id)
                                            <option value="{{ $assign_user->id }}">{{ $assign_user->getFullName() }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                @endforeach
            </form>
            <div class="col-sm-12 text-large margin-top">{!! __h("If you are sure, type :delete and click the red button.", ['delete' => '<span class="text-danger">DELETE</span>']) !!}</div>
            <div class="col-sm-6 col-sm-offset-3 margin-top-10 margin-bottom">
                <div class="input-group">
                    <input type="text" class="form-control input-delete-user" placeholder="{!! __h("Type :delete", ['delete' => '&quot;DELETE&quot;']) !!}">
                    <span class="input-group-btn">
                        <button class="btn btn-danger button-delete-user" disabled="disabled"><i class="glyphicon glyphicon-ok"></i></button>
                    </span>
                </div>
            </div>
            <div class="clearfix"></div>
        </div>
        </div>
    </div>
@endsection

@section('javascript')
    @parent
    userProfileInit();
@endsection
