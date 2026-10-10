@extends('layouts.app')
@section('page_width', 'wide')
@section('title_full', __('Logs').' - '.ucfirst($channel))
@section('main_class', 'fruit-ui')
@section('sidebar')
    <x-page-nav :label="__('Logs')">
        <x-slot:title><h1>{{ __('Logs') }}</h1></x-slot:title>
    </x-page-nav>
@endsection
@section('content')
<div class="page-content logs-page channel-log">
    @section('logs_bar_filters')
        <x-fruit::select form="channel-log-filters" name="outcome" :aria-label="__('Status')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All') }}</option>
            <option value="failed" @selected($outcome === 'failed')>{{ __('Failed') }}</option>
        </x-fruit::select>
        <x-fruit::select form="channel-log-filters" name="mailbox_id" :aria-label="__('Mailbox')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All Mailboxes') }}</option>
            @foreach ($mailboxes as $mailbox_option)
                <option value="{{ $mailbox_option->id }}" @selected($mailbox_id == $mailbox_option->id)>{{ $mailbox_option->name }}</option>
            @endforeach
        </x-fruit::select>
    @endsection
    @include('secure/logs_menu', ['names' => App\ActivityLog::menuNames(), 'current_name' => 'out_'.$channel])
    <form id="channel-log-filters" method="GET" action="{{ route('logs.'.$channel) }}" hidden></form>
    @if ($entries->count())
        <div class="f-table__scroll">
            <x-fruit::table class="logs-table" :aria-label="ucfirst($channel)">
                <thead><tr>
                    <th>{{ __('Date') }}</th><th>{{ __('Mailbox') }}</th><th>{{ __('Conversation') }}</th>
                    <th>{{ __('Customer') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Details') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td>{{ App\User::dateFormat($entry['date'], 'M j, H:i:s') }}</td>
                            <td>{{ $entry['mailbox']->name ?? '—' }}</td>
                            <td>@if ($entry['conversation'])<a href="{{ route('conversations.view', ['id' => $entry['conversation']->id]) }}">#{{ $entry['conversation']->number }}</a>@else — @endif</td>
                            <td>{{ $entry['customer'] }}</td>
                            <td>{{ $entry['type'] }}</td>
                            <td class="logs-table__long"><span><x-fruit::badge :tone="$entry['tone']">{{ $entry['status'] }}</x-fruit::badge> <span class="f-muted">{{ $entry['message'] }}</span></span></td>
                            <td>
                                @if ($entry['details'] || $entry['message'])
                                    <x-fruit::button variant="ghost" size="small" x-data x-on:click="$dispatch('fruit-dialog-open', { name: 'channel-{{ $entry['key'] }}' })">{{ __('Details') }}</x-fruit::button>
                                    <x-fruit::dialog name="channel-{{ $entry['key'] }}" aria-labelledby="channel-{{ $entry['key'] }}-title">
                                        <header class="f-dialog__header"><h2 id="channel-{{ $entry['key'] }}-title">{{ ucfirst($channel) }}</h2></header>
                                        <div class="f-dialog__body">
                                            <x-fruit::description-list>
                                                @if ($entry['message'])<div><dt>{{ __('Details') }}</dt><dd class="channel-log__error">{{ $entry['message'] }}</dd></div>@endif
                                                @foreach ($entry['details'] as $label => $value)
                                                    <div><dt class="channel-log__error">{{ $label }}</dt><dd class="channel-log__error">{{ $value }}</dd></div>
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
        {{ $entries->links('fruit::pagination.default') }}
    @else
        <x-fruit::empty-state><x-slot:icon><x-icon.file-text /></x-slot:icon>{{ __('This log is empty.') }}</x-fruit::empty-state>
    @endif
</div>
@endsection
