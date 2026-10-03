{{-- $table from Report::timeTable() or ProductivityReport::countTable(). --}}
<h4>{{ $title }}</h4>
@if ($table['count'])
    <table class="table table-striped table-condensed rpt-table">
        @foreach ($table['rows'] as $row)
            <tr>
                <td>{{ $row['title'] }}</td>
                <td class="rpt-bar-col"><span class="rpt-bar" style="width: {{ $row['percent'] }}%"></span></td>
                <td class="text-right">{{ $row['percent'] }}%</td>
                <td class="text-right">@if ($row['change'] !== null)<small class="text-help">{{ $row['change'] > 0 ? '+' : '' }}{{ $row['change'] }}</small>@endif</td>
            </tr>
        @endforeach
        <tr class="rpt-table-summary">
            @if (array_key_exists('median', $table))
                <td colspan="2">{{ __('Median') }}</td>
                <td class="text-right" colspan="2">{{ App\Reports\Report::duration($table['median']) }}</td>
            @else
                <td colspan="2">{{ __('Average') }}</td>
                <td class="text-right" colspan="2">{{ $table['average'] }}</td>
            @endif
        </tr>
    </table>
@else
    <p class="text-help">{{ __('Nothing in this period.') }}</p>
@endif
