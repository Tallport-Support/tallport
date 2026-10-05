{{-- The productivity report's figures (App\Livewire\ReportResults). --}}
    @include('reports/partials/metrics', ['metrics' => [
        'customers_helped' => [__('Customers Helped'), __('Customers who received replies.')],
        'replies'          => [__('Replies Sent'), __('Replies to customers, including new conversations started by users.')],
        'replies_day'      => [__('Replies per Day'), __('Replies per day on average.')],
        'closed'           => [__('Closed'), __('Conversations closed in this period.')],
        'rfr'              => [__('Resolved on First Reply'), __('Conversations closed in this period after a single reply.')],
    ]])

    @include('reports/partials/chart')

    <div class="rpt-tables">
        @include('reports/partials/time_table', ['title' => __('First Response Time'), 'table' => $data['table_first_response_time']])
        @include('reports/partials/time_table', ['title' => __('Response Time'), 'table' => $data['table_response_time']])
        @include('reports/partials/time_table', ['title' => __('Resolution Time'), 'table' => $data['table_resolution_time']])
        @include('reports/partials/time_table', ['title' => __('Replies to Resolve'), 'table' => $data['table_replies_to_resolve']])
    </div>
    <p class="f-help">{{ __('Response times count every hour, from the customer\'s first message waiting for a reply. Median: half took less.') }}</p>

    <h2 class="f-title-3 rpt-section-title">{{ __('Users') }}</h2>
    @if ($data['table_users'])
        <div class="f-table__scroll">
            <x-fruit::table class="rpt-table">
                <thead>
                    <tr>
                        <th>{{ __('User') }}</th>
                        <th class="rpt-num">{{ __('Replies Sent') }}</th>
                        <th class="rpt-num">{{ __('Closed') }}</th>
                        <th class="rpt-num">{{ __('Customers Helped') }}</th>
                        <th class="rpt-num">{{ __('First Response Time') }}</th>
                        <th class="rpt-num">{{ __('Response Time') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['table_users'] as $row)
                        <tr>
                            <td><a href="{{ $this->urlWith(['user' => $row['user_id']]) }}">{{ $row['name'] }}</a></td>
                            <td class="rpt-num">{{ $row['replies'] }}</td>
                            <td class="rpt-num">{{ $row['closed'] }}</td>
                            <td class="rpt-num">{{ $row['customers_helped'] }}</td>
                            <td class="rpt-num">{{ App\Reports\Report::duration($row['first_response_time']) }}</td>
                            <td class="rpt-num">{{ App\Reports\Report::duration($row['response_time']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-fruit::table>
        </div>
    @else
        <p class="f-muted">{{ __('Nothing in this period.') }}</p>
    @endif
