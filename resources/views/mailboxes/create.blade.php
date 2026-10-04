@extends('layouts.app')

@section('title', __('New Mailbox'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav>
        <x-slot:title><h1>{{ __('Create a mailbox') }}</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
<div class="page-content">
    @include('partials/flash_messages')

    <form class="settings-form" method="POST" action="">
        {{ csrf_field() }}

        <p class="f-help">{{ __('Customers email this address for help (e.g. support@domain.com)') }}</p>

        <x-fruit::field :label="__('Email Address')" :description="__('You can edit this later')">
            <x-fruit::input type="email" id="email" name="email" :value="old('email')" maxlength="128" required autofocus />
        </x-fruit::field>

        <x-fruit::field :label="__('Mailbox Name')">
            <x-fruit::input id="name" name="name" :value="old('name')" maxlength="40" required />
        </x-fruit::field>

        @if (\Module::isActive('satratings'))
            <x-fruit::field :label="__('Satisfaction Ratings')">
                <x-fruit::select id="ratings" name="ratings" required>
                    <option value="1" @selected((int) old('ratings'))>{{ __('On') }}</option>
                    <option value="0" @selected((int) old('ratings'))>{{ __('Off') }}</option>
                </x-fruit::select>
            </x-fruit::field>
        @endif

        <x-fruit::fieldset id="permissions-fields">
            <legend>{{ __('Who Else Will Use This Mailbox') }}</legend>
            @foreach ($users as $user_option)
                <x-fruit::checkbox name="users[]" id="user-{{ $user_option->id }}" :value="$user_option->id" :checked="is_array(old('users')) && in_array($user_option->id, old('users'))">{{ $user_option->first_name }} {{ $user_option->last_name }}</x-fruit::checkbox>
            @endforeach
        </x-fruit::fieldset>
        <div class="f-row settings-form__note">
            <a href="#" class="f-button f-button--ghost f-button--small sel-all">{{ __('all') }}</a>
            <a href="#" class="f-button f-button--ghost f-button--small sel-none">{{ __('none') }}</a>
        </div>
        @error('users')<p class="f-error">{{ $message }}</p>@enderror

        <div class="settings-form__actions f-row">
            <x-fruit::button type="submit" variant="primary">{{ __('Create Mailbox') }}</x-fruit::button>
            <a href="{{ route('mailboxes') }}" class="f-button f-button--ghost">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection

@section('javascript')
    @parent
    permissionsInit();
@endsection