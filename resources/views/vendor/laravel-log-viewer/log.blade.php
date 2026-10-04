@extends('layouts.app')

@section('title_full', __('Logs').' - '.__('App Logs'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @php
          $names = App\ActivityLog::select('log_name')->distinct()->pluck('log_name')->toArray();
          array_unshift($names, App\ActivityLog::NAME_OUT_EMAILS);
          array_push($names, App\ActivityLog::NAME_APP_LOGS);
          $current_name = 'app';
        @endphp
    <x-page-nav :label="__('Logs')">
        <x-slot:title><h1>{{ __('Logs') }}</h1></x-slot:title>
        @foreach ($names as $name)
            <a href="{{ route('logs', ['name' => $name]) }}" @if ($current_name == $name) aria-current="page" @endif>{{ App\ActivityLog::getLogTitle($name) }}</a>
        @endforeach
    </x-page-nav>
@endsection

@section('content')
    @php
        $file_query = $current_file ? ['l' => \Illuminate\Support\Facades\Crypt::encrypt($current_file)] : [];
        $level_tones = ['emergency' => 'danger', 'alert' => 'danger', 'critical' => 'danger', 'error' => 'danger', 'failed' => 'danger', 'warning' => 'warning', 'processed' => 'success'];
    @endphp
    <div class="page-content app-logs">
        <form class="f-toolbar app-logs__toolbar" method="GET" action="">
            @if ($current_file)
                <input type="hidden" name="l" value="{{ $file_query['l'] }}">
            @endif
            <x-fruit::menu :title="__('File')" class="app-logs__files">
                <x-slot:trigger class="f-button--small"><x-heroicon-o-document-text class="f-icon" aria-hidden="true" /> {{ $current_file ?: __('File') }}</x-slot:trigger>
                @foreach ($files as $file)
                    <x-fruit::menu-link :href="'?l='.\Illuminate\Support\Facades\Crypt::encrypt($file)" :aria-current="$current_file == $file ? 'true' : null">{{ $file }}</x-fruit::menu-link>
                @endforeach
            </x-fruit::menu>
            @if ($levels)
                <select name="level" class="f-input app-logs__level" aria-label="{{ __('Level') }}">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($levels as $level)
                        <option value="{{ $level }}" @selected(request('level') === $level)>{{ ucfirst($level) }}</option>
                    @endforeach
                </select>
            @endif
            <x-fruit::search name="q" :value="request('q')" :label="__('Search')" :wrapper="['class' => 'app-logs__search']" />
            <span class="f-toolbar__spacer"></span>
            <span class="f-muted">{{ App\User::dateFormat(new Illuminate\Support\Carbon()) }}</span>
        </form>

        @if ($logs === null)
            <x-fruit::alert tone="warning">{{ __('This log file is larger than 50 MB. Download it to read it.') }}</x-fruit::alert>
        @elseif (!$logs->total())
            <x-fruit::empty-state>
                <x-slot:icon><x-heroicon-o-document-text /></x-slot:icon>
                {{ __('No log records.') }}
            </x-fruit::empty-state>
        @else
            <table class="f-table app-logs__table">
                <thead>
                    <tr>
                        @if ($standardFormat)
                            <th>{{ __('Level') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Message') }}</th>
                        @else
                            <th>#</th>
                            <th>{{ __('Message') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            @if ($standardFormat)
                                <td><span class="f-badge f-badge--{{ $level_tones[$log['level']] ?? 'neutral' }}">{{ ucfirst($log['level']) }}</span></td>
                            @endif
                            <td class="app-logs__date">{{ $log['date'] }}</td>
                            <td class="app-logs__text">
                                @if ($standardFormat && $log['context'])<span class="f-muted">{{ $log['context'] }}</span> @endif{{ $log['text'] }}
                                @if (isset($log['in_file']))
                                    <br/>{{ $log['in_file'] }}
                                @endif
                                @if ($log['stack'])
                                    <details class="app-logs__stack">
                                        <summary>{{ __('Details') }}</summary>
                                        <pre>{{ trim($log['stack']) }}</pre>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $logs->links('fruit::pagination.default') }}
        @endif

        @if ($current_file)
            <div class="f-row app-logs__actions">
                <a class="f-button f-button--small" href="?dl={{ \Illuminate\Support\Facades\Crypt::encrypt($current_file) }}"><x-heroicon-o-arrow-down-tray class="f-icon" aria-hidden="true" /> {{ __('Download') }}</a>
                <span class="f-toolbar__spacer"></span>
                <a class="f-button f-button--small f-button--ghost" id="delete-log" href="?del={{ \Illuminate\Support\Facades\Crypt::encrypt($current_file) }}" data-confirm="{{ __('Delete this log file?') }}">{{ __('Delete') }}</a>
                @if (count($files) > 1)
                    <a class="f-button f-button--small f-button--danger" id="delete-all-log" href="?delall=true&amp;_token={{ csrf_token() }}" data-confirm="{{ __('Delete all log files?') }}">{{ __('Delete All') }}</a>
                @endif
            </div>
        @endif
    </div>
@endsection

@section('javascript')
    @parent
    initLogsTable();
@endsection
