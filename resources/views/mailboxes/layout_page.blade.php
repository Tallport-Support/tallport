@extends('layouts.app')

{{-- A mailbox's further page as a level (layouts/app): Settings › Mailboxes › the mailbox (for those who
     may open it) › the page, whose content is its 'content' section. A page with a Save button of its
     own (page_footer) shows it at its end. --}}
@section('title', App\Misc\MailboxSettings::currentTitle($mailbox, Auth::user(), Route::currentRouteName()))

@section('drilldown')
    @php
        $mailbox_section = Route::currentRouteName();
        $mailbox_opens = App\Misc\MailboxSettings::canOpenMailbox($mailbox, Auth::user());
    @endphp
    @include('mailboxes/columns/mailboxes')
    @if ($mailbox_opens)
        @include('mailboxes/columns/mailbox')
    @endif
    <x-fruit::drilldown-column :title="App\Misc\MailboxSettings::currentTitle($mailbox, Auth::user(), $mailbox_section)" id="mailbox-page">
        <x-slot:back>
            @if ($mailbox_opens)
                <x-fruit::back-link :href="route('mailboxes.update', ['id' => $mailbox->id])" wire:navigate>{{ $mailbox->name }}</x-fruit::back-link>
            @else
                <x-fruit::back-link :href="route('mailboxes')" wire:navigate>{{ __('Mailboxes') }}</x-fruit::back-link>
            @endif
        </x-slot:back>
        <x-fruit::page :width="trim($__env->yieldContent('page_width')) ?: 'narrow'">
            @yield('content')
            @hasSection('page_footer')
                <div class="f-row settings-form__actions">@yield('page_footer')</div>
            @endif
        </x-fruit::page>
    </x-fruit::drilldown-column>
@endsection
