@extends('layouts.app')

@section('content')
<div class="auth-page">

    @include('auth/banner')

    <x-fruit::card class="auth-card">
        <h1 class="f-title-3 auth-card__title">{{ __('Reset Password') }}</h1>

        <form class="f-stack" method="POST" action="{{ route('password.request') }}">
            {{ csrf_field() }}

            <input type="hidden" name="token" value="{{ $token }}">

            <x-fruit::field :label="__('Email Address')">
                <x-fruit::input id="email" type="email" name="email" autocomplete="email" :value="$email ?? old('email')" required autofocus />
            </x-fruit::field>

            <x-fruit::field :label="__('Password')">
                <x-fruit::input id="password" type="password" name="password" autocomplete="new-password" required />
            </x-fruit::field>

            <x-fruit::field :label="__('Confirm Password')">
                <x-fruit::input id="password-confirm" type="password" name="password_confirmation" autocomplete="new-password" required />
            </x-fruit::field>

            <div class="auth-card__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Reset Password') }}</x-fruit::button>
            </div>
        </form>
    </x-fruit::card>
</div>
@endsection
