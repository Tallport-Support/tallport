@extends('layouts.app')

@section('title', __('Manage Mailboxes'))

@section('page_width', 'narrow')

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav :label="__('Mailboxes')">
        <x-slot:title><h1>{{ __('Mailboxes') }}</h1></x-slot:title>
        <x-slot:actions>
            @if (Auth::user()->isAdmin())
                <a wire:navigate href="{{ route('mailboxes.create') }}" class="f-button">{{ __('New Mailbox') }}</a>
            @endif
        </x-slot:actions>
    </x-page-nav>
@endsection

@section('content')
<div class="page-content">
    {{-- Each mailbox: its colour, name and address, and its people (or what needs setting up); its page next. --}}
    <x-fruit::form-section class="mailboxes-list" :aria-label="__('Mailboxes')">
        @foreach ($mailboxes as $mailbox_item)
            @php
                [$mailbox_connection, $mailbox_problem] = $mailbox_item->isConnected() ? [null, false] : App\Misc\MailboxSettings::connection($mailbox_item);
            @endphp
            <a wire:navigate href="{{ App\Misc\MailboxSettings::url($mailbox_item, Auth::user()) }}" class="f-form-row f-form-row--link mailbox-row">
                <span class="mailbox-row__main">
                    {{-- A module's image of the mailbox (mailbox_card.before_name) instead of its colour. --}}
                    @if (!Eventy::filter('mailbox.has_img', false, $mailbox_item))
                        <x-icon.mail class="f-icon mailbox-row__icon" :data-fruit-mark="$mailbox_item->accent ?: 'blue'" aria-hidden="true" />
                    @endif
                    <span class="mailbox-row__identity">
                        <span class="mailbox-row__name">@action('mailbox_card.before_name', $mailbox_item){{ $mailbox_item->name }}@if ($mailbox_item->isArchived()) <x-fruit::badge>{{ __('Archived') }}</x-fruit::badge>@endif</span>
                        <span class="mailbox-row__address">{{ $mailbox_item->email }}</span>
                    </span>
                </span>
                <span class="f-form-row__value @if ($mailbox_problem) mailbox-pages__problem @endif">{{ $mailbox_problem ? $mailbox_connection : trans_choice(':count person|:count people', count($mailbox_item->usersHavingAccess(true))) }}</span>
            </a>
        @endforeach
    </x-fruit::form-section>
</div>
@endsection
