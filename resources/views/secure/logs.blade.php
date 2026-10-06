@extends('layouts.app')

@section('page_width', 'wide')

@section('title_full', __('Logs').' - '.App\ActivityLog::getLogTitle($current_name))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav :label="__('Logs')">
        <x-slot:title><h1>{{ __('Logs') }}</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
@php
    // The entry's long text, kept to one line here (its Details show it whole).
    $long_col = collect(['status', 'error', 'event'])->first(fn ($col) => in_array($col, $cols));
    // A cell's value as text or a link.
    $log_cell = function ($row, $col) {
        $value = $row[$col] ?? null;
        if ($value === null || $value === '') {
            return '';
        }
        if ($col == 'conversation' && is_string($value) && preg_match("/^#[0-9]+\-([0-9]+)$/", $value, $m)) {
            $value = App\Thread::find($m[1]) ?: $value;
        }
        if ($col == 'thread' && !is_object($value)) {
            $value = App\Thread::find($value) ?: $value;
        }
        if ($col == 'user' || $col == 'customer') {
            return '<a href="'.e($value->url()).'">'.e($value->getFullName(true)).'</a>';
        }
        if ($col == 'date') {
            return e(App\User::dateFormat(new Illuminate\Support\Carbon($value), 'M j, H:i:s'));
        }
        if (is_object($value) && $value instanceof App\Thread) {
            return '<a href="'.e(route('conversations.view', ['id' => $value->conversation_id])).'#thread-'.$value->id.'" target="_blank">#'.e($value->conversation->number ?? '').'</a>';
        }

        return e($value);
    };
@endphp
<div class="page-content logs-page">
    @include('secure/logs_menu', ['clearable' => $current_name != App\ActivityLog::NAME_OUT_EMAILS && count($logs)])

    @if (count($logs))
        <div class="f-table__scroll">
            <x-fruit::table id="table-logs" class="logs-table" :aria-label="App\ActivityLog::getLogTitle($current_name)">
                <thead>
                    <tr>
                        @foreach ($cols as $col)
                            <th>{{ App\ActivityLog::formatColTitle($col) }}</th>
                        @endforeach
                        <th><span class="f-sr-only">{{ __('Details') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $row_index => $row)
                        <tr>
                            @foreach ($cols as $col)
                                @if ($col == $long_col)
                                    <td class="logs-table__long"><span>{!! $log_cell($row, $col) !!}</span></td>
                                @else
                                    <td>{!! $log_cell($row, $col) !!}</td>
                                @endif
                            @endforeach
                            <td>
                                <x-fruit::button variant="ghost" size="small" x-data x-on:click="$dispatch('fruit-dialog-open', { name: 'log-entry-{{ $row_index }}' })">{{ __('Details') }}</x-fruit::button>
                                <x-fruit::dialog name="log-entry-{{ $row_index }}" aria-labelledby="log-entry-{{ $row_index }}-title">
                                    <header class="f-dialog__header"><h2 id="log-entry-{{ $row_index }}-title">{{ App\ActivityLog::getLogTitle($current_name) }}</h2></header>
                                    <div class="f-dialog__body">
                                        <x-fruit::description-list>
                                            @foreach ($cols as $col)
                                                @if (($entry_value = $log_cell($row, $col)) !== '')
                                                    <div><dt>{{ App\ActivityLog::formatColTitle($col) }}</dt><dd>{!! $entry_value !!}</dd></div>
                                                @endif
                                            @endforeach
                                        </x-fruit::description-list>
                                    </div>
                                    <form method="dialog" class="f-dialog__footer"><x-fruit::button type="submit" variant="primary">{{ __('Done') }}</x-fruit::button></form>
                                </x-fruit::dialog>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-fruit::table>
        </div>

        {{ $activities->links('fruit::pagination.default') }}
    @else
        <x-fruit::empty-state>
            <x-slot:icon><x-icon.file-text /></x-slot:icon>
            {{ __('This log is empty.') }}
        </x-fruit::empty-state>
    @endif
</div>
@endsection
