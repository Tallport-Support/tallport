@extends('layouts.app')

@section('title', $own ? __('Profile') : $user->getFullName())

@section('title_full', __('Edit User').' - '.$user->getFullName())

{{-- One's own Profile (/profile): a level beside the sidebar. Someone in Settings › Users (oneself
     too): Users, then the person, managed. --}}
@section('drilldown')
    @if ($own)
        <x-fruit::drilldown-column :title="__('Profile')" id="user">
            <livewire:user-profile :user="$user" mode="own" />
        </x-fruit::drilldown-column>
    @else
        @include('users/columns/users')
        @include('users/columns/user', ['user_section' => null])
    @endif
@endsection
