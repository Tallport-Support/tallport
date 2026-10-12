@extends('layouts.app')

@section('title', $user->id == Auth::id() ? __('Profile') : $user->getFullName())

@section('title_full', __('Edit User').' - '.$user->getFullName())

{{-- One's own profile: a level beside the sidebar (Profile in it). Someone else's: Settings › Users,
     then the person. --}}
@section('drilldown')
    @if ($user->id == Auth::id())
        <x-fruit::drilldown-column :title="__('Profile')" id="user">
            <livewire:user-profile :user="$user" :rows="false" />
        </x-fruit::drilldown-column>
    @else
        @include('users/columns/users')
        @include('users/columns/user', ['user_section' => null])
    @endif
@endsection
