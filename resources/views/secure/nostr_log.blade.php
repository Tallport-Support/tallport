@extends('layouts.app')

@section('page_width', 'wide')

@section('title_full', __('Logs').' - '.__('Outgoing Nostr'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav :label="__('Logs')">
        <x-slot:title><h1>{{ __('Logs') }}</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
{{-- Manage » Logs » Outgoing Nostr (ChannelLogsController): each message sent over Nostr, newest first. --}}
<div class="page-content logs-page channel-log">
    @section('logs_bar_filters')
        <x-fruit::select form="nostr-log-filters" name="outcome" :aria-label="__('Status')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All') }}</option>
            <option value="failed" @selected($outcome == 'failed')>{{ __('Failed') }}</option>
        </x-fruit::select>
        <x-fruit::select form="nostr-log-filters" name="mailbox_id" :aria-label="__('Mailbox')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All Mailboxes') }}</option>
            @foreach ($mailboxes as $mailbox_option)
                <option value="{{ $mailbox_option->id }}" @selected($mailbox_id == $mailbox_option->id)>{{ $mailbox_option->name }}</option>
            @endforeach
        </x-fruit::select>
    @endsection
    @include('secure/logs_menu', ['names' => App\ActivityLog::menuNames(), 'current_name' => App\ActivityLog::NAME_OUT_NOSTR])
    <form id="nostr-log-filters" method="GET" action="{{ route('logs.nostr') }}" hidden></form>

    @if ($events->count())
        <div class="f-table__scroll">
            <x-fruit::table id="table-nostr-log" class="logs-table" :aria-label="__('Outgoing Nostr')">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Mailbox') }}</th>
                        <th>{{ __('Conversation') }}</th>
                        <th>{{ __('Customer') }}</th>
                        <th>{{ __('Relays') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th><span class="f-sr-only">{{ __('Details') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        @php
                            $sent = $event->status == App\Nostr\NostrEvent::STATUS_OK;
                            $relays = $event->getRelays();
                            $accepted = count(array_filter($relays, fn ($result) => !empty($result['ok'])));
                            $customer = $event->conversation ? $event->conversation->customer : null;
                            $npub = $event->pubkey ? App\Nostr\Keys::shortNpub($event->pubkey) : '';
                        @endphp
                        <tr>
                            <td>{{ App\User::dateFormat($event->created_at, 'M j, H:i:s') }}</td>
                            <td>@if ($event->mailbox){{ $event->mailbox->name }}@endif</td>
                            <td>@if ($event->conversation)<a href="{{ route('conversations.view', ['id' => $event->conversation_id]) }}" target="_blank">#{{ $event->conversation->number }}</a>@else<span class="channel-log__none">—</span>@endif</td>
                            <td>
                                @if ($customer){{ $customer->getFullName(true) }}@endif
                                @if ($npub !== '')<span class="f-muted">{{ $npub }}</span>@elseif (!$customer)<span class="channel-log__none">—</span>@endif
                            </td>
                            <td>@if ($relays){{ __(':accepted of :total', ['accepted' => $accepted, 'total' => count($relays)]) }}@else<span class="channel-log__none">—</span>@endif</td>
                            {{-- The outcome (an auto reply has no thread of its own until sent), and the error on one line. --}}
                            <td class="logs-table__long"><span>@if (!$event->thread_id)<x-fruit::badge variant="outline">{{ __('Auto Reply') }}</x-fruit::badge> @endif<x-fruit::badge :tone="$sent ? 'success' : 'danger'">{{ $sent ? __('Succeeded') : __('Failed') }}</x-fruit::badge> <span class="f-muted">{{ $event->error }}</span></span></td>
                            <td>
                                @if ($relays || (string) $event->error !== '')
                                    <x-fruit::button variant="ghost" size="small" x-data x-on:click="$dispatch('fruit-dialog-open', { name: 'nostr-event-{{ $event->id }}' })">{{ __('Details') }}</x-fruit::button>
                                    <x-fruit::dialog name="nostr-event-{{ $event->id }}" aria-labelledby="nostr-event-{{ $event->id }}-title">
                                        <header class="f-dialog__header"><h2 id="nostr-event-{{ $event->id }}-title">{{ __('Outgoing Nostr') }}</h2></header>
                                        <div class="f-dialog__body">
                                            <x-fruit::description-list>
                                                <div><dt>{{ __('Date') }}</dt><dd>{{ App\User::dateFormat($event->created_at, 'M j, H:i:s') }}</dd></div>
                                                @if ($event->pubkey)
                                                    <div><dt>{{ __('Key') }}</dt><dd class="channel-log__error">{{ App\Nostr\Keys::npub($event->pubkey) }}</dd></div>
                                                @endif
                                                <div><dt>{{ __('Status') }}</dt><dd>{{ $sent ? __('Succeeded') : __('Failed') }}</dd></div>
                                                @if ((string) $event->error !== '')
                                                    <div><dt>{{ __('Error') }}</dt><dd class="channel-log__error">{{ $event->error }}</dd></div>
                                                @endif
                                                @foreach ($relays as $relay_url => $result)
                                                    <div><dt class="channel-log__error">{{ $relay_url }}</dt><dd class="channel-log__error">@if (!empty($result['ok'])){{ __('Accepted') }}@else{{ __('Failed') }}@if ((string) ($result['message'] ?? '') !== ''): {{ $result['message'] }}@endif @endif</dd></div>
                                                @endforeach
                                            </x-fruit::description-list>
                                        </div>
                                        <form method="dialog" class="f-dialog__footer"><x-fruit::button type="submit" variant="primary">{{ __('Done') }}</x-fruit::button></form>
                                    </x-fruit::dialog>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-fruit::table>
        </div>

        {{ $events->links('fruit::pagination.default') }}
    @else
        <x-fruit::empty-state>
            <x-slot:icon><x-icon.file-text /></x-slot:icon>
            {{ __('This log is empty.') }}
        </x-fruit::empty-state>
    @endif
</div>
@endsection
