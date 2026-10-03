<form class="rpt-filters form-inline" method="GET" action="{{ url()->current() }}">
    <select name="period" class="form-control input-sm" aria-label="{{ __('Period') }}">
        @foreach (App\Reports\Report::periodNames() as $period => $period_name)
            <option value="{{ $period }}" @if ($report->filters['period'] == $period) selected @endif>{{ $period_name }}</option>
        @endforeach
    </select>
    <input type="date" name="from" class="form-control input-sm" value="{{ $report->filters['from'] }}" aria-label="{{ __('From') }}">
    <span class="text-help">–</span>
    <input type="date" name="to" class="form-control input-sm" value="{{ $report->filters['to'] }}" aria-label="{{ __('To') }}">
    @if (count($report->mailboxes()) > 1)
        <select name="mailbox" class="form-control input-sm" aria-label="{{ __('Mailbox') }}">
            <option value="">{{ __('All Mailboxes') }}</option>
            @foreach ($report->mailboxes() as $mailbox)
                <option value="{{ $mailbox->id }}" @if ($report->filters['mailbox'] == $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
            @endforeach
        </select>
    @endif
    <select name="type" class="form-control input-sm" aria-label="{{ __('Type') }}">
        <option value="">{{ __('All Types') }}</option>
        @foreach (App\Reports\Report::types() as $type => $type_name)
            <option value="{{ $type }}" @if ($report->filters['type'] == $type) selected @endif>{{ $type_name }}</option>
        @endforeach
    </select>
    @if ($report->hasUserFilter())
        <select name="user" class="form-control input-sm" aria-label="{{ __('User') }}">
            <option value="">{{ __('All Users') }}</option>
            @foreach ($report->users() as $user)
                <option value="{{ $user->id }}" @if ($report->filters['user'] == $user->id) selected @endif>{{ $user->getFullName() }}</option>
            @endforeach
        </select>
    @endif
    <input type="hidden" name="chart" value="{{ $data['chart']['type'] }}">
    <input type="hidden" name="group_by" value="{{ $data['chart']['group_by'] }}">
    <noscript><button type="submit" class="btn btn-default btn-sm">{{ __('Refresh') }}</button></noscript>
</form>
