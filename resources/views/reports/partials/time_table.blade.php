{{-- $table from Report::timeTable() or ProductivityReport::countTable(). --}}
<section>
    <h2 class="f-title-3 rpt-section-title">{{ $title }}</h2>
    @if ($table['count'])
        <x-fruit::table class="rpt-table">
            <tbody>
                @foreach ($table['rows'] as $row)
                    <tr>
                        <td>{{ $row['title'] }}</td>
                        <td class="rpt-bar-col"><span class="rpt-bar" style="width: {{ $row['percent'] }}%"></span></td>
                        <td class="rpt-num">{{ $row['percent'] }}%</td>
                        <td class="rpt-num">@if ($row['change'] !== null)<small class="f-muted">{{ $row['change'] > 0 ? '+' : '' }}{{ $row['change'] }}</small>@endif</td>
                    </tr>
                @endforeach
                <tr class="rpt-table-summary">
                    @if (array_key_exists('median', $table))
                        <td colspan="2">{{ __('Median') }}</td>
                        <td class="rpt-num" colspan="2">{{ App\Reports\Report::duration($table['median']) }}</td>
                    @else
                        <td colspan="2">{{ __('Average') }}</td>
                        <td class="rpt-num" colspan="2">{{ $table['average'] }}</td>
                    @endif
                </tr>
            </tbody>
        </x-fruit::table>
    @else
        <p class="f-muted">{{ __('Nothing in this period.') }}</p>
    @endif
</section>
