@extends('layouts.app')

@section('page_width', 'narrow')

@section('title', __('New User'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav>
        <x-slot:back><x-fruit::back-link wire:navigate :href="route('users')">{{ __('Users') }}</x-fruit::back-link></x-slot:back>
        <x-slot:title><h1>{{ __('Create a New User') }}</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
<div class="page-content">
    @include('partials/flash_messages')

    @php
        $send_invite = !empty(old('send_invite')) || empty(old('role'));
    @endphp
    <form id="page-form" class="settings-form" method="POST" action="" x-data="{ sendInvite: @js($send_invite) }">
        {{ csrf_field() }}

        @if (Auth::user()->isAdmin())
            <x-fruit::form-section :title="__('Account')">
                <x-fruit::field :label="__('Role')" layout="row">
                    <x-fruit::select id="role" name="role" required autofocus>
                        <option value="{{ App\User::ROLE_USER }}" @selected(old('role') == App\User::ROLE_USER)>{{ __('User') }}</option>
                        <option value="{{ App\User::ROLE_ADMIN }}" @selected(old('role') == App\User::ROLE_ADMIN)>{{ __('Administrator') }}</option>
                    </x-fruit::select>
                    <x-slot:description>{!! __('<strong>Administrators</strong> can create new users and have access to all mailboxes and settings') !!}<br>{!! __('<strong>Users</strong> have access to the mailbox(es) specified in their permissions') !!}</x-slot:description>
                </x-fruit::field>
            </x-fruit::form-section>
        @endif

        <x-fruit::form-section :title="__('Profile')">
            <x-fruit::field :label="__('First Name')" layout="row">
                <x-fruit::input id="first_name" name="first_name" :value="old('first_name')" maxlength="20" required autofocus />
            </x-fruit::field>

            <x-fruit::field :label="__('Last Name')" layout="row">
                <x-fruit::input id="last_name" name="last_name" :value="old('last_name')" maxlength="30" />
            </x-fruit::field>

            @action('user.create.before_email')

            <x-fruit::field :label="__('Email')" layout="row">
                <x-fruit::input type="email" id="email" name="email" :value="old('email')" maxlength="100" required />
            </x-fruit::field>
        </x-fruit::form-section>

        @if (count($mailboxes))
            <x-fruit::form-section :title="__('Mailboxes')">
                <x-fruit::fieldset id="permissions-fields">
                    <legend>{{ __('Which mailboxes will user use?') }}</legend>
                    @foreach ($mailboxes as $mailbox_option)
                        <x-fruit::checkbox name="mailboxes[]" id="mailbox-{{ $mailbox_option->id }}" :value="$mailbox_option->id" :checked="is_array(old('mailboxes')) && in_array($mailbox_option->id, old('mailboxes'))">{{ $mailbox_option->name }}</x-fruit::checkbox>
                    @endforeach
                </x-fruit::fieldset>
                <div class="f-form-row">
                    <div class="f-row" x-data="{ check(on) { document.querySelectorAll('#permissions-fields input').forEach(input => input.checked = on) } }">
                        <button type="button" class="f-button f-button--ghost f-button--small" @click="check(true)">{{ __('All') }}</button>
                        <button type="button" class="f-button f-button--ghost f-button--small" @click="check(false)">{{ __('None') }}</button>
                    </div>
                    @error('mailboxes')<p class="f-error">{{ $message }}</p>@enderror
                </div>
            </x-fruit::form-section>
        @endif

        <x-fruit::form-section :title="__('Password')">
            <x-fruit::field :label="__('Send an Invite Email')" :description="__('An invite can be sent later if you aren\'t ready')" layout="row">
                <x-fruit::switch id="send_invite" name="send_invite" value="1" :checked="$send_invite" x-model="sendInvite" />
            </x-fruit::field>

            <x-fruit::field :label="__('Password')" layout="row" x-show="!sendInvite">
                <x-fruit::input type="password" id="password" name="password" :value="old('password')" maxlength="255" x-bind:required="!sendInvite" />
            </x-fruit::field>
        </x-fruit::form-section>

    </form>
</div>
@endsection

@section('page_footer')
    <a href="{{ route('users') }}" class="f-button f-button--ghost">{{ __('Cancel') }}</a>
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Create User') }}</x-fruit::button>
@endsection
