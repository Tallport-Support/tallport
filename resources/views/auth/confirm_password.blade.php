@extends('layouts.app')

@section('content')
<div class="auth-page">
    <x-fruit::card class="auth-card">
        <form class="f-stack" method="POST" action="{{ route('password.confirm.store') }}">
            {{ csrf_field() }}

            <p>{{ __('Please confirm your password to continue.') }}</p>

            <x-fruit::field :label="__('Password')">
                <x-fruit::input id="password" type="password" name="password" autocomplete="current-password" required autofocus />
            </x-fruit::field>

            <div class="auth-card__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Confirm') }}</x-fruit::button>
            </div>
        </form>
    </x-fruit::card>
</div>
@endsection
