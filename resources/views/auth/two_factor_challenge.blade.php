@extends('layouts.app')

@section('content')
<div class="auth-page">

    @include('auth/banner')

    <x-fruit::card class="auth-card">
        <form class="f-stack" method="POST" action="{{ route('two-factor.login.store') }}" x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
            {{ csrf_field() }}

            <div x-show="!recovery">
                <x-fruit::field :label="__('Code')" :description="__('Enter the code from your authenticator app.')">
                    <x-fruit::input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus />
                </x-fruit::field>
            </div>
            <div x-show="recovery" x-cloak>
                <x-fruit::field :label="__('Recovery Code')">
                    <x-fruit::input id="recovery_code" type="text" name="recovery_code" autocomplete="off" />
                </x-fruit::field>
            </div>

            <x-fruit::checkbox name="remember_device" value="1">{{ __('Remember this device for :days days', ['days' => App\Auth\TrustedDevices::DAYS]) }}</x-fruit::checkbox>

            <div class="auth-card__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Login') }}</x-fruit::button>
                <x-fruit::button variant="ghost" x-show="!recovery" x-on:click="recovery = true; document.getElementById('code').value = ''; $nextTick(() => document.getElementById('recovery_code').focus())">{{ __('Use a recovery code') }}</x-fruit::button>
            </div>
        </form>
    </x-fruit::card>
</div>
@endsection
