@extends('layouts.app')

@section('title_full', __('Logs').' - '.App\ActivityLog::getLogTitle($current_name))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('system/sidebar_menu')
    @include('secure/logs_menu')
@endsection

@section('content')
<div class="page-content">
    <form method="post" class="page-toolbar f-row">
        {{ csrf_field() }}
        <h2 class="f-title-3">{{ App\ActivityLog::getLogTitle($current_name) }}</h2>
        <div class="f-row">
            <span class="f-muted">{{ App\User::dateFormat(new Illuminate\Support\Carbon()) }}</span>
            @if ($current_name != App\ActivityLog::NAME_OUT_EMAILS)
                <x-fruit::button type="submit" size="small" name="action" value="clean">{{ __('Clear Log') }}</x-fruit::button>
            @endif
        </div>
    </form>

    @if (count($logs))
        <div class="f-table__scroll">
        <x-fruit::table id="table-logs" class="logs-table">
            <thead>
                <tr>
                    @foreach ($cols as $col)
                        <th>{{ App\ActivityLog::formatColTitle($col) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($logs as $row)
                    <tr>
                        @foreach ($cols as $col)
                            <td class="break-words">
                                @if (isset($row[$col]))
                                    @php
                                        if ($col == 'conversation' && preg_match("/^#[0-9]+\-([0-9]+)$/", $row[$col], $m)) {
                                            $thread_id = $m[1] ?? '';
                                            if ($thread_id) {
                                                $row[$col] = App\Thread::find($thread_id) ?: $row[$col];
                                            }
                                        }
                                        if ($col == 'thread') {
                                            $row[$col] = App\Thread::find($row[$col]) ?: $row[$col];
                                        }
                                    @endphp
                                    @if ($col == 'user' || $col == 'customer')
                                        <a href="{{ $row[$col]->url() }}">{{ $row[$col]->getFullName(true) }}</a>
                                    @elseif ($col == 'date')
                                        {{  App\User::dateFormat(new Illuminate\Support\Carbon($row[$col]), 'M j, H:i:s') }}
                                    @elseif (is_object($row[$col]) && get_class($row[$col]) == 'App\Thread')
                                        <a href="{{ route('conversations.view', ['id' => $row[$col]->conversation_id]) }}#thread-{{ $row[$col]->id }}" target="_blank">#{{ $row[$col]->conversation->number }}</a>
                                    @else
                                        {{ $row[$col] }}
                                    @endif
                                @else
                                    &nbsp;
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </x-fruit::table>
        </div>

        {{ $activities->links('fruit::pagination.default') }}

    @else
        <x-fruit::empty-state>
            <x-slot:icon><x-icon.file-text /></x-slot:icon>
            {{ __('Log is empty') }}
        </x-fruit::empty-state>
    @endif
</div>
@endsection