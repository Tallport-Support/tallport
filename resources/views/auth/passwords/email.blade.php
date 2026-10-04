@extends('layouts.app')

@section('content')
<div class="auth-page">

    @include('auth/banner')

    <x-fruit::card class="auth-card">
        <h1 class="f-title-3 auth-card__title">{{ __('Reset Password') }}</h1>

        @if (session('status'))
            <x-fruit::alert tone="success">{{ session('status') }}</x-fruit::alert>
        @endif

        <form class="f-stack" method="POST" action="{{ route('password.email') }}">
            {{ csrf_field() }}

            <x-fruit::field :label="__('Email Address')">
                <x-fruit::input id="email" type="email" name="email" autocomplete="email" :value="old('email')" required />
            </x-fruit::field>

            <div class="auth-card__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Send Password Reset Link') }}</x-fruit::button>
            </div>
        </form>
    </x-fruit::card>
</div>
@endsection
