@extends('layouts.app')

@section('title', __('New User'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav>
        <x-slot:title><h1>{{ __('Create a New User') }}</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
<div class="page-content">
    @include('partials/flash_messages')

    @php
        $send_invite = !empty(old('send_invite')) || empty(old('role'));
    @endphp
    <form class="settings-form" method="POST" action="" x-data="{ sendInvite: @js($send_invite) }">
        {{ csrf_field() }}

        @if (Auth::user()->isAdmin())
            <x-fruit::field :label="__('Role')">
                <x-fruit::select id="role" name="role" required autofocus>
                    <option value="{{ App\User::ROLE_USER }}" @selected(old('role') == App\User::ROLE_USER)>{{ __('User') }}</option>
                    <option value="{{ App\User::ROLE_ADMIN }}" @selected(old('role') == App\User::ROLE_ADMIN)>{{ __('Administrator') }}</option>
                </x-fruit::select>
            </x-fruit::field>
            <p class="f-help settings-form__note">{!! __('<strong>Administrators</strong> can create new users and have access to all mailboxes and settings') !!}<br>{!! __('<strong>Users</strong> have access to the mailbox(es) specified in their permissions') !!}</p>
        @endif

        <x-fruit::field :label="__('First Name')">
            <x-fruit::input id="first_name" name="first_name" :value="old('first_name')" maxlength="20" required autofocus />
        </x-fruit::field>

        <x-fruit::field :label="__('Last Name')">
            <x-fruit::input id="last_name" name="last_name" :value="old('last_name')" maxlength="30" />
        </x-fruit::field>

        @action('user.create.before_email')

        <x-fruit::field :label="__('Email')">
            <x-fruit::input type="email" id="email" name="email" :value="old('email')" maxlength="100" required />
        </x-fruit::field>

        @if (count($mailboxes))
            <x-fruit::fieldset id="permissions-fields">
                <legend>{{ __('Which mailboxes will user use?') }}</legend>
                @foreach ($mailboxes as $mailbox_option)
                    <x-fruit::checkbox name="mailboxes[]" id="mailbox-{{ $mailbox_option->id }}" :value="$mailbox_option->id" :checked="is_array(old('mailboxes')) && in_array($mailbox_option->id, old('mailboxes'))">{{ $mailbox_option->name }}</x-fruit::checkbox>
                @endforeach
            </x-fruit::fieldset>
            <div class="f-row settings-form__note">
                <a href="#" class="f-button f-button--ghost f-button--small sel-all">{{ __('all') }}</a>
                <a href="#" class="f-button f-button--ghost f-button--small sel-none">{{ __('none') }}</a>
            </div>
            @error('mailboxes')<p class="f-error">{{ $message }}</p>@enderror
        @endif

        <hr>

        <x-fruit::switch id="send_invite" name="send_invite" value="1" :checked="$send_invite" x-model="sendInvite" :description="__('An invite can be sent later if you aren\'t ready')">{{ __('Send an invite email') }}</x-fruit::switch>

        <x-fruit::field :label="__('Password')" x-show="!sendInvite">
            <x-fruit::input type="password" id="password" name="password" :value="old('password')" maxlength="255" x-bind:required="!sendInvite" />
        </x-fruit::field>

        <div class="settings-form__actions f-row">
            <x-fruit::button type="submit" variant="primary">{{ __('Create User') }}</x-fruit::button>
            <a href="{{ route('users') }}" class="f-button f-button--ghost">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection

@section('javascript')
    @parent
    permissionsInit();
@endsection
