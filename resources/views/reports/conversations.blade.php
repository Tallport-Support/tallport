@extends('layouts.app')

@section('title', __('Conversations Report'))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('reports/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        {{ __('Conversations Report') }}
    </div>

    <div class="container rpt-report">
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

        <div class="row">
            @if ($data['table_mailboxes'])
                <div class="col-xs-12">
                    <h4>{{ __('Mailboxes') }}</h4>
                    <div class="table-responsive">
                        <table class="table table-striped table-condensed rpt-table">
                            <tr>
                                <th>{{ __('Mailbox') }}</th>
                                <th class="text-right">{{ __('New Conversations') }}</th>
                                <th class="text-right">{{ __('Messages Received') }}</th>
                                <th class="text-right">{{ __('Replies Sent') }}</th>
                                <th class="text-right">{{ __('Closed') }}</th>
                                <th class="text-right">{{ __('First Response Time') }}</th>
                                <th class="text-right">{{ __('Resolution Time') }}</th>
                            </tr>
                            @foreach ($data['table_mailboxes'] as $row)
                                <tr>
                                    <td><a href="{{ request()->fullUrlWithQuery(['mailbox' => $row['mailbox_id']]) }}">{{ $row['name'] }}</a></td>
                                    <td class="text-right">{{ $row['new'] }}</td>
                                    <td class="text-right">{{ $row['messages'] }}</td>
                                    <td class="text-right">{{ $row['replies'] }}</td>
                                    <td class="text-right">{{ $row['closed'] }}</td>
                                    <td class="text-right">{{ App\Reports\Report::duration($row['first_response_time']) }}</td>
                                    <td class="text-right">{{ App\Reports\Report::duration($row['resolution_time']) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                    <p class="text-help">{{ __('Times are medians: half of the conversations took less.') }}</p>
                </div>
            @endif
            <div class="col-xs-12 col-md-8">
                <h4>{{ __('Most Active Customers') }}</h4>
                @if ($data['table_customers'])
                    <table class="table table-striped table-condensed rpt-table">
                        @foreach ($data['table_customers'] as $row)
                            <tr>
                                <td><a href="{{ route('customers.update', ['id' => $row['customer_id']]) }}">{{ $row['name'] }}</a></td>
                                <td class="text-right">{{ $row['messages'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @else
                    <p class="text-help">{{ __('Nothing in this period.') }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    @parent
    reportsInit();
@endsection
