{{-- $metrics: key => [title, help]; values in $data['metrics']. --}}
<div class="rpt-metrics">
    @foreach ($metrics as $key => [$title, $help])
        @php
            $metric = $data['metrics'][$key];
        @endphp
        <div class="rpt-metric">
            <div class="rpt-metric-title">{{ $title }} @if ($help)<i class="glyphicon glyphicon-info-sign text-help" data-toggle="tooltip" title="{{ $help }}"></i>@endif</div>
            <div class="rpt-metric-value">{{ $metric['value'] ?? '–' }}@include('reports/partials/change', ['change' => $metric['change']])</div>
        </div>
    @endforeach
</div>
