@extends('layouts.app')

@section('page_width', 'narrow')

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

    <form id="page-form" class="settings-form" method="POST" action="">
        {{ csrf_field() }}

        <x-fruit::form-section :title="__('Mailbox')" :footer="__('Customers email this address for help (e.g. support@domain.com)')">
            <x-fruit::field :label="__('Email Address')" :description="__('You can edit this later')" layout="row">
                <x-fruit::input type="email" id="email" name="email" :value="old('email')" maxlength="128" required autofocus />
            </x-fruit::field>

            <x-fruit::field :label="__('Mailbox Name')" layout="row">
                <x-fruit::input id="name" name="name" :value="old('name')" maxlength="40" required />
            </x-fruit::field>

            @if (\Module::isActive('satratings'))
                <x-fruit::field :label="__('Satisfaction Ratings')" layout="row">
                    <x-fruit::select id="ratings" name="ratings" required>
                        <option value="1" @selected((int) old('ratings'))>{{ __('On') }}</option>
                        <option value="0" @selected((int) old('ratings'))>{{ __('Off') }}</option>
                    </x-fruit::select>
                </x-fruit::field>
            @endif
        </x-fruit::form-section>

        <x-fruit::form-section :title="__('Users')">
            <x-fruit::fieldset id="permissions-fields">
                <legend>{{ __('Who Else Will Use This Mailbox') }}</legend>
                @foreach ($users as $user_option)
                    <x-fruit::checkbox name="users[]" id="user-{{ $user_option->id }}" :value="$user_option->id" :checked="is_array(old('users')) && in_array($user_option->id, old('users'))">{{ $user_option->first_name }} {{ $user_option->last_name }}</x-fruit::checkbox>
                @endforeach
            </x-fruit::fieldset>
            <div class="f-row">
                <button type="button" class="f-button f-button--ghost f-button--small" x-data x-on:click="document.querySelectorAll('#permissions-fields input').forEach(input => input.checked = true)">{{ __('all') }}</button>
                <button type="button" class="f-button f-button--ghost f-button--small" x-data x-on:click="document.querySelectorAll('#permissions-fields input').forEach(input => input.checked = false)">{{ __('none') }}</button>
            </div>
            @error('users')<p class="f-error">{{ $message }}</p>@enderror
        </x-fruit::form-section>

    </form>
</div>
@endsection

@section('page_footer')
    <a href="{{ route('mailboxes') }}" class="f-button f-button--ghost">{{ __('Cancel') }}</a>
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Create Mailbox') }}</x-fruit::button>
@endsection
