@extends('layouts.app')

@section('title', __('Change Your Password'))

@section('title_full', __('Change Your Password').' - '.$user->getFullName())

{{-- Profile › Change Your Password (one's own), saved with its button: the current password goes with
     the new one. --}}
@section('drilldown')
    <x-fruit::drilldown-column :title="__('Profile')" id="user">
        <livewire:user-profile :user="$user" mode="own" section="password" />
    </x-fruit::drilldown-column>
    <x-fruit::drilldown-column :title="__('Change Your Password')" id="user-password">
        <x-slot:back><x-fruit::back-link :href="route('own.profile')" wire:navigate>{{ __('Profile') }}</x-fruit::back-link></x-slot:back>
        <x-fruit::page width="narrow">
            <form id="page-form" class="settings-form" method="POST" action="{{ route('users.password.save', ['id' => $user->id]) }}">
                {{ csrf_field() }}

                <x-fruit::form-section>
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

                <div class="f-row settings-form__actions">
                    <a href="{{ route('own.profile') }}" class="f-button f-button--ghost" wire:navigate>{{ __('Cancel') }}</a>
                    <x-fruit::button type="submit" variant="primary">{{ __('Save Password') }}</x-fruit::button>
                </div>
            </form>
        </x-fruit::page>
    </x-fruit::drilldown-column>
@endsection
