@extends('layouts.app')

@section('title_full', __('Change your password').' - '.$user->getFullName())

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        <form class="settings-form" method="POST" action="">
            {{ csrf_field() }}

            <h2 class="settings-form__heading">{{ __('Change your password') }}</h2>

            <x-fruit::field :label="__('Current Password')">
                <x-fruit::input type="password" id="password_current" name="password_current" :value="old('password_current')" required autofocus />
            </x-fruit::field>

            <x-fruit::field :label="__('New Password')">
                <x-fruit::input type="password" id="password" name="password" :value="old('password')" minlength="8" required />
            </x-fruit::field>

            <x-fruit::field :label="__('Confirm Password')">
                <x-fruit::input type="password" id="password_confirmation" name="password_confirmation" :value="old('password_confirmation')" minlength="8" required />
            </x-fruit::field>

            <div class="settings-form__actions f-row">
                <x-fruit::button type="submit" variant="primary">{{ __('Save Password') }}</x-fruit::button>
                <a href="{{ route('users.profile', ['id' => $user->id]) }}" class="f-button f-button--ghost">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
@endsection

@section('javascript')
    @parent
    userProfileInit();
@endsection