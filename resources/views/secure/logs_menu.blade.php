{{-- The Logs page's bar (secure/logs, App Logs, AI): which log, its filters, and Clear Log… for one that can be cleared ($clearable). --}}
<form method="post" action="{{ route('logs.action', ['name' => $current_name]) }}" class="f-toolbar logs-bar" x-data>
    {{ csrf_field() }}
    <input type="hidden" name="action" value="clean">
    <label class="f-label" for="logs-name">{{ __('Log') }}</label>
    <select id="logs-name" class="f-input logs-bar__select" x-on:change="Livewire.navigate($el.value)">
        @foreach ($names as $name)
            <option value="{{ App\ActivityLog::logUrl($name) }}" @selected($current_name == $name)>{{ App\ActivityLog::getLogTitle($name) }}</option>
        @endforeach
    </select>
    {{-- A log's own filters (they belong to its GET form through their form attribute). --}}
    @yield('logs_bar_filters')
    <span class="f-toolbar__spacer"></span>
    @if (!empty($clearable))
        <button type="button" class="f-button f-button--danger f-button--small" x-on:click="$confirm({ title: @js(__('Clear :log?', ['log' => App\ActivityLog::getLogTitle($current_name)])), message: @js(__('Its entries are deleted for good.')), confirm: @js(__('Clear Log')), tone: 'danger' }).then((confirmed) => confirmed && $root.submit())">{{ __('Clear Log…') }}</button>
    @endif
</form>
