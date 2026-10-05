@extends('layouts.app')

@section('title', __('Manage Mailboxes'))

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
    <x-fruit::item-list class="entity-list" :aria-label="__('Mailboxes')">
        @foreach ($mailboxes as $mailbox_item)
            <li>
                <a href="{{ route('mailboxes.update', ['id' => $mailbox_item->id]) }}" class="f-item-row @if (!Eventy::filter('mailbox.has_img', false, $mailbox_item)) no-img @endif @if (!$mailbox_item->isConnected() || $mailbox_item->isArchived()) entity-list__inactive @endif">
                    <span class="f-item-row__top">
                        <span class="f-item-row__title">@action('mailbox_card.before_name', $mailbox_item){{ $mailbox_item->name }}</span>
                        @if ($mailbox_item->isArchived())
                            <span class="f-item-row__time"><x-fruit::badge>{{ __('Archived') }}</x-fruit::badge></span>
                        @endif
                    </span>
                    <span class="f-item-row__subtitle">{{ $mailbox_item->email }}</span>
                </a>
            </li>
        @endforeach
    </x-fruit::item-list>
</div>
@endsection
