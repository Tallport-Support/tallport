{{-- The filters apply when changed; a chosen date makes the period custom. --}}
<form id="rpt_filters" class="rpt-filters f-row" method="GET" action="{{ url()->current() }}" x-data x-on:change="if ($event.target.matches('select, input[type=date]')) { if ($event.target.type == 'date') { $el.elements['period'].value = 'custom' } $el.submit() }">
    <x-fruit::select name="period" :aria-label="__('Period')">
        @foreach (App\Reports\Report::periodNames() as $period => $period_name)
            <option value="{{ $period }}" @selected($report->filters['period'] == $period)>{{ $period_name }}</option>
        @endforeach
    </x-fruit::select>
    <x-fruit::date name="from" :value="$report->filters['from']" :aria-label="__('From')" />
    <span class="f-muted">–</span>
    <x-fruit::date name="to" :value="$report->filters['to']" :aria-label="__('To')" />
    @if (count($report->mailboxes()) > 1)
        <x-fruit::select name="mailbox" :aria-label="__('Mailbox')">
            <option value="">{{ __('All Mailboxes') }}</option>
            @foreach ($report->mailboxes() as $mailbox)
                <option value="{{ $mailbox->id }}" @selected($report->filters['mailbox'] == $mailbox->id)>{{ $mailbox->name }}</option>
            @endforeach
        </x-fruit::select>
    @endif
    <x-fruit::select name="type" :aria-label="__('Type')">
        <option value="">{{ __('All Types') }}</option>
        @foreach (App\Reports\Report::types() as $type => $type_name)
            <option value="{{ $type }}" @selected($report->filters['type'] == $type)>{{ $type_name }}</option>
        @endforeach
    </x-fruit::select>
    @if ($report->hasUserFilter())
        <x-fruit::select name="user" :aria-label="__('User')">
            <option value="">{{ __('All Users') }}</option>
            @foreach ($report->users() as $user)
                <option value="{{ $user->id }}" @selected($report->filters['user'] == $user->id)>{{ $user->getFullName() }}</option>
            @endforeach
        </x-fruit::select>
    @endif
    <input type="hidden" name="chart" value="{{ request()->query('chart') }}">
    @if (count($report->groupBys()) <= 1)
        <input type="hidden" name="group_by" value="{{ $report->groupBys()[0] }}">
    @endif
    <noscript><x-fruit::button type="submit" size="small">{{ __('Refresh') }}</x-fruit::button></noscript>
</form>
