@extends('layouts.app')

@section('page_width', 'wide')

@section('title_full', __('Logs').' - '.__('Outgoing Telegram'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav :label="__('Logs')">
        <x-slot:title><h1>{{ __('Logs') }}</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
{{-- Manage » Logs » Outgoing Telegram (ChannelLogsController): each try at sending a reply, newest first. --}}
<div class="page-content logs-page channel-log">
    @section('logs_bar_filters')
        <x-fruit::select form="telegram-log-filters" name="outcome" :aria-label="__('Status')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All') }}</option>
            <option value="failed" @selected($outcome == 'failed')>{{ __('Failed') }}</option>
        </x-fruit::select>
        <x-fruit::select form="telegram-log-filters" name="mailbox_id" :aria-label="__('Mailbox')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All Mailboxes') }}</option>
            @foreach ($mailboxes as $mailbox_option)
                <option value="{{ $mailbox_option->id }}" @selected($mailbox_id == $mailbox_option->id)>{{ $mailbox_option->name }}</option>
            @endforeach
        </x-fruit::select>
    @endsection
    @include('secure/logs_menu', ['names' => App\ActivityLog::menuNames(), 'current_name' => App\ActivityLog::NAME_OUT_TELEGRAM])
    <form id="telegram-log-filters" method="GET" action="{{ route('logs.telegram') }}" hidden></form>

    @if ($sends->count())
        <div class="f-table__scroll">
            <x-fruit::table id="table-telegram-log" class="logs-table" :aria-label="__('Outgoing Telegram')">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Mailbox') }}</th>
                        <th>{{ __('Conversation') }}</th>
                        <th>{{ __('Customer') }}</th>
                        <th>{{ __('Attempt') }}</th>
                        <th>{{ __('Message IDs') }}</th>
                        <th>{{ __('Files') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th><span class="f-sr-only">{{ __('Details') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sends as $send)
                        @php
                            [$status_name, $status_tone] = $send->statusName();
                            $username = App\Telegram\TelegramSend::username($send->customer);
                        @endphp
                        <tr>
                            <td>{{ App\User::dateFormat($send->created_at, 'M j, H:i:s') }}</td>
                            <td>@if ($send->mailbox){{ $send->mailbox->name }}@endif</td>
                            <td>@if ($send->conversation)<a href="{{ route('conversations.view', ['id' => $send->conversation_id]) }}" target="_blank">#{{ $send->conversation->number }}</a>@else<span class="channel-log__none">—</span>@endif</td>
                            <td>
                                @if ($send->customer)
                                    {{ $send->customer->getFullName(true) }}
                                    @if ($username !== '')<span class="f-muted">{{ '@'.$username }}</span>@endif
                                @else
                                    <span class="channel-log__none">—</span>
                                @endif
                            </td>
                            <td>{{ $send->attempt }}</td>
                            <td>@if ($send->message_ids){{ implode(', ', $send->message_ids) }}@else<span class="channel-log__none">—</span>@endif</td>
                            <td>@if ($send->files){{ $send->files }}@else<span class="channel-log__none">—</span>@endif</td>
                            {{-- The outcome, and the error on one line (whole in its Details). --}}
                            <td class="logs-table__long"><span><x-fruit::badge :tone="$status_tone">{{ $status_name }}</x-fruit::badge> <span class="f-muted">{{ $send->error }}</span></span></td>
                            <td>
                                @if ((string) $send->error !== '')
                                    <x-fruit::button variant="ghost" size="small" x-data x-on:click="$dispatch('fruit-dialog-open', { name: 'telegram-send-{{ $send->id }}' })">{{ __('Details') }}</x-fruit::button>
                                    <x-fruit::dialog name="telegram-send-{{ $send->id }}" aria-labelledby="telegram-send-{{ $send->id }}-title">
                                        <header class="f-dialog__header"><h2 id="telegram-send-{{ $send->id }}-title">{{ __('Outgoing Telegram') }}</h2></header>
                                        <div class="f-dialog__body">
                                            <x-fruit::description-list>
                                                <div><dt>{{ __('Date') }}</dt><dd>{{ App\User::dateFormat($send->created_at, 'M j, H:i:s') }}</dd></div>
                                                <div><dt>{{ __('Attempt') }}</dt><dd>{{ $send->attempt }}</dd></div>
                                                <div><dt>{{ __('Status') }}</dt><dd>{{ $status_name }}</dd></div>
                                                <div><dt>{{ __('Error') }}</dt><dd class="channel-log__error">{{ $send->error }}</dd></div>
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

        {{ $sends->links('fruit::pagination.default') }}
    @else
        <x-fruit::empty-state>
            <x-slot:icon><x-icon.file-text /></x-slot:icon>
            {{ __('This log is empty.') }}
        </x-fruit::empty-state>
    @endif
</div>
@endsection
