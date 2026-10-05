@extends('layouts.app')

@section('title', __('Manage Users'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav :label="__('Users')">
        <x-slot:title><h1>{{ __('Users') }}@if (count($users)) <span class="f-muted">({{ count($users) }})</span>@endif</h1></x-slot:title>
        <x-slot:actions>
            <a wire:navigate href="{{ route('users.create') }}" class="f-button">{{ __('New User') }}</a>
        </x-slot:actions>
    </x-page-nav>
@endsection

@section('content')
<div class="page-content" x-data="{ q: '' }">
    @if (count($users) > 1)
        <div class="list-search">
            <x-fruit::search id="search-users" x-model="q" :label="__('Search Users')" :placeholder="__('Search Users')" />
        </div>
    @endif

    <x-fruit::item-list id="users-list" class="entity-list" :aria-label="__('Users')">
        @foreach ($users as $user)
            <li x-show="!q || $el.dataset.search.includes(q.trim().toLowerCase())" data-search="{{ mb_strtolower($user->first_name.' '.$user->last_name.' '.$user->email) }}">
                <a href="{{ route('users.profile', ['id' => $user->id]) }}" class="f-item-row @if ($user->invite_state != App\User::INVITE_STATE_ACTIVATED || $user->isDisabled()) entity-list__inactive @endif">
                    @if ($user->photo_url)
                        <span class="f-avatar f-item-row__leading" aria-hidden="true"><img src="{{ $user->getPhotoUrl() }}" alt=""></span>
                    @else
                        <span class="f-avatar f-item-row__leading" aria-hidden="true">{{ strtoupper($user->first_name[0] ?? '') }}{{ strtoupper($user->last_name[0] ?? '') }}</span>
                    @endif
                    <span class="f-item-row__top">
                        <span class="f-item-row__title">{{ $user->first_name }} {{ $user->last_name }}</span>
                        <span class="f-item-row__time entity-list__badges">
                            @if ($user->isAdmin())<x-fruit::badge tone="accent">{{ __('Administrator') }}</x-fruit::badge>@endif
                            @if ($user->invite_state == App\User::INVITE_STATE_SENT)<x-fruit::badge>{{ __('Invited') }}</x-fruit::badge>@elseif ($user->invite_state == App\User::INVITE_STATE_NOT_INVITED)<x-fruit::badge>{{ __('Not Invited') }}</x-fruit::badge>@endif
                            @if ($user->invite_state == App\User::INVITE_STATE_ACTIVATED && $user->isDisabled())<x-fruit::badge tone="danger">{{ __('Disabled') }}</x-fruit::badge>@endif
                        </span>
                    </span>
                    <span class="f-item-row__subtitle">@filter('users.email', $user->email)</span>
                </a>
            </li>
        @endforeach
    </x-fruit::item-list>
</div>
@endsection
