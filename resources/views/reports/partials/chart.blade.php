<div class="rpt-chart-box">
    <div class="rpt-chart-header f-row">
        <x-fruit::select class="rpt-chart-type" :aria-label="__('Chart')" x-data x-on:change="const filters = document.getElementById('rpt_filters'); filters.elements['chart'].value = $el.value; filters.submit()">
            @foreach ($data['chart']['types'] as $chart_type => $chart_name)
                <option value="{{ $chart_type }}" @selected($data['chart']['type'] == $chart_type)>{{ $chart_name }}</option>
            @endforeach
        </x-fruit::select>
        <span class="rpt-chart-legend"><i class="rpt-legend-current"></i> {{ __('This Period') }} <i class="rpt-legend-previous"></i> {{ __('Previous Period') }}</span>
        @if (count($data['chart']['group_bys']) > 1)
            <fieldset class="f-fieldset" x-data x-on:change="document.getElementById('rpt_filters').submit()">
                <legend class="f-sr-only">{{ __('Group by') }}</legend>
                <div class="f-segmented">
                    @foreach ($data['chart']['group_bys'] as $group_by)
                        <label><input type="radio" name="group_by" value="{{ $group_by }}" form="rpt_filters" class="rpt-group-by" @checked($data['chart']['group_by'] == $group_by)><span>{{ ['d' => __('Day'), 'w' => __('Week'), 'm' => __('Month')][$group_by] }}</span></label>
                    @endforeach
                </div>
            </fieldset>
        @endif
    </div>
    {!! App\Reports\Chart::svg($data['chart']) !!}
</div>
