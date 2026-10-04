@extends('layouts.app')

@section('page_width', 'narrow')

@section('title_full', __('Change your password').' - '.$user->getFullName())

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        <form id="page-form" class="settings-form" method="POST" action="">
            {{ csrf_field() }}

            <x-fruit::form-section :title="__('Change your password')">
                <x-fruit::field :label="__('Current Password')" layout="row">
                    <x-fruit::input type="password" id="password_current" name="password_current" :value="old('password_current')" required autofocus />
                </x-fruit::field>

                <x-fruit::field :label="__('New Password')" layout="row">
                    <x-fruit::input type="password" id="password" name="password" :value="old('password')" minlength="8" required />
                </x-fruit::field>

                <x-fruit::field :label="__('Confirm Password')" layout="row">
                    <x-fruit::input type="password" id="password_confirmation" name="password_confirmation" :value="old('password_confirmation')" minlength="8" required />
                </x-fruit::field>
            </x-fruit::form-section>

        </form>
    </div>
@endsection

@section('page_footer')
    <a href="{{ route('users.profile', ['id' => $user->id]) }}" class="f-button f-button--ghost">{{ __('Cancel') }}</a>
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save Password') }}</x-fruit::button>
@endsection
