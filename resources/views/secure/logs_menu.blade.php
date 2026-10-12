{{-- A log's bar (secure/logs, App Logs, AI, the channels' logs): its filters, and Clear Log… for one that can be cleared ($clearable); which log is a row in the Logs (secure/columns/logs). --}}
<form method="post" action="{{ route('logs.action', ['name' => $current_name]) }}" class="f-toolbar logs-bar" x-data>
    {{ csrf_field() }}
    <input type="hidden" name="action" value="clean">
    {{-- A log's own filters (they belong to its GET form through their form attribute). --}}
    @yield('logs_bar_filters')
    <span class="f-toolbar__spacer"></span>
    @if (!empty($clearable))
        <button type="button" class="f-button f-button--danger f-button--small" x-on:click="$confirm({ title: @js(__('Clear :log?', ['log' => App\ActivityLog::getLogTitle($current_name)])), message: @js(__('Its entries are deleted for good.')), confirm: @js(__('Clear Log')), tone: 'danger' }).then((confirmed) => confirmed && $root.submit())">{{ __('Clear Log…') }}</button>
    @endif
</form>
