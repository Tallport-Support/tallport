<div class="rpt-chart-box">
    <div class="rpt-chart-header">
        <select class="form-control input-sm rpt-chart-type" aria-label="{{ __('Chart') }}">
            @foreach ($data['chart']['types'] as $chart_type => $chart_name)
                <option value="{{ $chart_type }}" @if ($data['chart']['type'] == $chart_type) selected @endif>{{ $chart_name }}</option>
            @endforeach
        </select>
        <span class="rpt-chart-legend"><i class="rpt-legend-current"></i> {{ __('This Period') }} <i class="rpt-legend-previous"></i> {{ __('Previous Period') }}</span>
        @if (count($data['chart']['group_bys']) > 1)
            <div class="btn-group btn-group-sm pull-right">
                @foreach ($data['chart']['group_bys'] as $group_by)
                    <button type="button" class="btn btn-default rpt-group-by @if ($data['chart']['group_by'] == $group_by) active @endif" data-group-by="{{ $group_by }}">{{ ['d' => __('Day'), 'w' => __('Week'), 'm' => __('Month')][$group_by] }}</button>
                @endforeach
            </div>
        @endif
    </div>
    {!! App\Reports\Chart::svg($data['chart']) !!}
</div>
