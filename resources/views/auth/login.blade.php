@extends('layouts.app')

@section('content')
<div class="auth-page">

    @include('auth/banner')

    <x-fruit::card class="auth-card">

        @action('login_form.before')

        <form class="f-stack" method="POST" action="{{ route('login') }}">
            {{ csrf_field() }}

            <x-fruit::field :label="__('Email Address')">
                <x-fruit::input id="email" type="email" name="email" autocomplete="email" :value="old('email')" required autofocus />
            </x-fruit::field>

            <x-fruit::field :label="__('Password')">
                <x-fruit::input id="password" type="password" name="password" autocomplete="current-password" required />
            </x-fruit::field>

            <x-fruit::checkbox name="remember" :checked="(bool) old('remember')">{{ __('Remember Me') }}</x-fruit::checkbox>

            @action('login_form.before_submit')

            <div class="auth-card__actions">
                <button type="submit" class="f-button f-button--primary @action('login_form.submit_class')" @action('login_form.submit_attrs')>
                    {{ __('Login') }}
                </button>

                <button type="button" class="f-button" id="passkey-login" x-data="tallportPasskeyLogin(@js(route('passkey.login-options')), @js(route('passkey.login')))" x-show="supported" @click="login($el)"><x-heroicon-o-finger-print class="f-icon" aria-hidden="true" /> {{ __('Sign in with a passkey') }}</button>

                @if (Eventy::filter('auth.password_reset_available', true))
                    <a class="f-button f-button--ghost" href="{{ route('password.request') }}">
                        {{ __('Forgot Your Password?') }}
                    </a>
                @endif
            </div>
        </form>

        @action('login_form.after')
    </x-fruit::card>
</div>
@endsection
