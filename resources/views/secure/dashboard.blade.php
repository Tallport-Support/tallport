@extends('layouts.app')

@section('title', __('Dashboard'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav>
        <x-slot:title><h1>{{ App\Option::getCompanyName() }} {{ __('Dashboard') }}@action('dashboard.heading_append')</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
<div class="page-content">
    @filter('dashboard.before', '')
    @if (count($mailboxes))
        <div class="dash-cards">
            @foreach ($mailboxes as $dash_mailbox)
                <x-fruit::card class="dash-card {{ (!$dash_mailbox->isConnected() || $dash_mailbox->isArchived()) ? 'dash-card-inactive' : '' }}" data-mailbox-id="{{ $dash_mailbox->id }}">
                    @action('dash_card.before_mailbox_name', $dash_mailbox)
                    <h2 class="f-title-3 dash-card__title"><a href="{{ $dash_mailbox->url() }}" class="mailbox-name">@include('mailboxes/partials/mute_icon', ['mailbox' => $dash_mailbox]){{ $dash_mailbox->name }}</a>@if ($dash_mailbox->isArchived()) <x-fruit::badge>{{ __('Archived') }}</x-fruit::badge>@endif</h2>
                    <div class="f-muted dash-card__email">{{ $dash_mailbox->email }}</div>

                    @if ($dash_mailbox->isConnected())
                        <div class="dash-card-list">
                            @php
                                $main_folders = $dash_mailbox->getMainFolders();
                            @endphp
                            @foreach ($main_folders as $folder)
                                @php
                                    $active_count = $folder->getCount($main_folders);
                                    $waiting_since = (!$folder->isIndirect() && $active_count) ? $folder->getWaitingSince() : null;
                                @endphp
                                <a href="{{ $folder->url($dash_mailbox->id) }}" class="dash-card-list-item @if (!$active_count) dash-card-item-empty @endif" title="@if ($active_count){{ __('Waiting Since') }}@else{{ __('View conversations') }}@endif">
                                    {{ $folder->getTypeName() }}
                                    @if ($waiting_since)<span class="waiting-since">{{ $waiting_since }}</span>@endif
                                    <x-fruit::badge :tone="$active_count && $loop->index < 2 ? 'accent' : 'neutral'">{{ $active_count }}</x-fruit::badge>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <p class="f-help">{{ __('Administrator has not configured mailbox connection settings yet.') }}</p>
                        @if (Auth::user()->can('update', $dash_mailbox))
                            @if (!$dash_mailbox->isOutActive())
                                <div><a href="{{ route('mailboxes.connection', ['id' => $dash_mailbox->id]) }}" class="f-button f-button--small">{{ __('Configure') }}</a></div>
                            @elseif (!$dash_mailbox->isInActive())
                                <div><a href="{{ route('mailboxes.connection.incoming', ['id' => $dash_mailbox->id]) }}" class="f-button f-button--small">{{ __('Configure') }}</a></div>
                            @endif
                        @endif
                    @endif

                    <div class="dash-card__footer f-row">
                        <a href="{{ $dash_mailbox->url() }}" class="f-button f-button--small">{{ __('Open Mailbox') }}</a>
                        @if (\Eventy::filter('mailbox.show_buttons', true, $dash_mailbox) && $dash_mailbox->isConnected())
                            <a href="{{ route('conversations.create', ['mailbox_id' => $dash_mailbox->id]) }}" class="f-button f-button--small f-button--ghost">{{ __('New Conversation') }}</a>
                        @endif
                        @if (\Eventy::filter('mailbox.show_buttons', true, $dash_mailbox) && Auth::user()->can('viewMailboxMenu', Auth::user()) && Auth::user()->can('update', $dash_mailbox))
                            <a href="{{ route('mailboxes.update', ['id' => $dash_mailbox->id]) }}" class="f-button f-button--small f-button--ghost f-button--icon dash-card__settings" title="{{ __('Mailbox Settings') }}" aria-label="{{ __('Mailbox Settings') }}"><x-heroicon-o-cog-6-tooth class="f-icon" aria-hidden="true" /></a>
                        @endif
                    </div>
                </x-fruit::card>
            @endforeach
        </div>
    @elseif (Auth::user()->isAdmin())
        <a href="{{ route('mailboxes') }}" class="f-button f-button--primary">{{ __("Manage Mailboxes") }}</a>
    @else
        <x-fruit::empty-state>
            <x-slot:icon><x-heroicon-o-home /></x-slot:icon>
            {{ __("Welcome home!") }}
        </x-fruit::empty-state>
    @endif
    @filter('dashboard.after', '')
</div>
@endsection
