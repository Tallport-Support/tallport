@extends('layouts.app')

@section('title', __('Productivity Report'))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('reports/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        {{ __('Productivity Report') }}
    </div>

    <div class="container rpt-report">
        @include('reports/partials/filters')

        @include('reports/partials/metrics', ['metrics' => [
            'customers_helped' => [__('Customers Helped'), __('Customers who received replies.')],
            'replies'          => [__('Replies Sent'), __('Replies to customers, including new conversations started by users.')],
            'replies_day'      => [__('Replies per Day'), __('Replies per day on average.')],
            'closed'           => [__('Closed'), __('Conversations closed in this period.')],
            'rfr'              => [__('Resolved on First Reply'), __('Conversations closed in this period after a single reply.')],
        ]])

        @include('reports/partials/chart')

        <div class="row">
            <div class="col-xs-12 col-sm-6">
                @include('reports/partials/time_table', ['title' => __('First Response Time'), 'table' => $data['table_first_response_time']])
            </div>
            <div class="col-xs-12 col-sm-6">
                @include('reports/partials/time_table', ['title' => __('Response Time'), 'table' => $data['table_response_time']])
            </div>
            <div class="col-xs-12 col-sm-6">
                @include('reports/partials/time_table', ['title' => __('Resolution Time'), 'table' => $data['table_resolution_time']])
            </div>
            <div class="col-xs-12 col-sm-6">
                @include('reports/partials/time_table', ['title' => __('Replies to Resolve'), 'table' => $data['table_replies_to_resolve']])
            </div>
        </div>
        <p class="text-help">{{ __('Response times count every hour, from the customer\'s first message waiting for a reply. Median: half took less.') }}</p>

        <h4>{{ __('Users') }}</h4>
        @if ($data['table_users'])
            <div class="table-responsive">
                <table class="table table-striped table-condensed rpt-table">
                    <tr>
                        <th>{{ __('User') }}</th>
                        <th class="text-right">{{ __('Replies Sent') }}</th>
                        <th class="text-right">{{ __('Closed') }}</th>
                        <th class="text-right">{{ __('Customers Helped') }}</th>
                        <th class="text-right">{{ __('First Response Time') }}</th>
                        <th class="text-right">{{ __('Response Time') }}</th>
                    </tr>
                    @foreach ($data['table_users'] as $row)
                        <tr>
                            <td><a href="{{ request()->fullUrlWithQuery(['user' => $row['user_id']]) }}">{{ $row['name'] }}</a></td>
                            <td class="text-right">{{ $row['replies'] }}</td>
                            <td class="text-right">{{ $row['closed'] }}</td>
                            <td class="text-right">{{ $row['customers_helped'] }}</td>
                            <td class="text-right">{{ App\Reports\Report::duration($row['first_response_time']) }}</td>
                            <td class="text-right">{{ App\Reports\Report::duration($row['response_time']) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @else
            <p class="text-help">{{ __('Nothing in this period.') }}</p>
        @endif
    </div>
@endsection

@section('javascript')
    @parent
    reportsInit();
@endsection
