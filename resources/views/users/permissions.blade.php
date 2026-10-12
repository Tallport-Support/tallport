@extends('layouts.app')

@section('title', __('Permissions').' - '.$user->getFullName())

@section('title_full', __('User Permissions').' - '.$user->getFullName())

{{-- Settings › Users › the person › Permissions, each a level (layouts/app). --}}
@section('drilldown')
    @include('users/columns/users', ['user_section' => 'permissions'])
    @include('users/columns/user', ['user_section' => 'permissions'])
    <x-fruit::drilldown-column :title="__('Permissions')" id="user-permissions">
        <x-slot:back><x-fruit::back-link :href="route('users.profile', ['id' => $user->id])" wire:navigate>{{ $user->getFullName() }}</x-fruit::back-link></x-slot:back>
        <livewire:user-permissions :user="$user" />
    </x-fruit::drilldown-column>
@endsection
