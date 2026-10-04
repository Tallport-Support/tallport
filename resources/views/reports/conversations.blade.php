@extends('layouts.app')

@section('title', __('Conversations Report'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('reports/sidebar_menu')
@endsection

@section('content')
    <div class="page-content rpt-report">
        @include('reports/partials/filters')

        @include('reports/partials/metrics', ['metrics' => [
            'total'     => [__('Conversations'), __('Conversations with messages from customers or replies in this period.')],
            'new'       => [__('New Conversations'), __('Conversations started in this period, by customers or users.')],
            'messages'  => [__('Messages Received'), __('Messages from customers.')],
            'customers' => [__('Customers'), __('Customers who wrote.')],
            'conv_day'  => [__('New per Day'), __('New conversations per day on average.')],
            'busy_day'  => [__('Busiest Day'), __('Day of the week with the most new conversations on average.')],
        ]])

        @include('reports/partials/chart')

        @if ($data['table_mailboxes'])
            <h2 class="f-title-3 rpt-section-title">{{ __('Mailboxes') }}</h2>
            <div class="f-table__scroll">
                <x-fruit::table class="rpt-table">
                    <thead>
                        <tr>
                            <th>{{ __('Mailbox') }}</th>
                            <th class="rpt-num">{{ __('New Conversations') }}</th>
                            <th class="rpt-num">{{ __('Messages Received') }}</th>
                            <th class="rpt-num">{{ __('Replies Sent') }}</th>
                            <th class="rpt-num">{{ __('Closed') }}</th>
                            <th class="rpt-num">{{ __('First Response Time') }}</th>
                            <th class="rpt-num">{{ __('Resolution Time') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['table_mailboxes'] as $row)
                            <tr>
                                <td><a href="{{ request()->fullUrlWithQuery(['mailbox' => $row['mailbox_id']]) }}">{{ $row['name'] }}</a></td>
                                <td class="rpt-num">{{ $row['new'] }}</td>
                                <td class="rpt-num">{{ $row['messages'] }}</td>
                                <td class="rpt-num">{{ $row['replies'] }}</td>
                                <td class="rpt-num">{{ $row['closed'] }}</td>
                                <td class="rpt-num">{{ App\Reports\Report::duration($row['first_response_time']) }}</td>
                                <td class="rpt-num">{{ App\Reports\Report::duration($row['resolution_time']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-fruit::table>
            </div>
            <p class="f-help">{{ __('Times are medians: half of the conversations took less.') }}</p>
        @endif

        <section class="rpt-customers">
            <h2 class="f-title-3 rpt-section-title">{{ __('Most Active Customers') }}</h2>
            @if ($data['table_customers'])
                <x-fruit::table class="rpt-table">
                    <tbody>
                        @foreach ($data['table_customers'] as $row)
                            <tr>
                                <td><a href="{{ route('customers.update', ['id' => $row['customer_id']]) }}">{{ $row['name'] }}</a></td>
                                <td class="rpt-num">{{ $row['messages'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-fruit::table>
            @else
                <p class="f-muted">{{ __('Nothing in this period.') }}</p>
            @endif
        </section>
    </div>
@endsection
