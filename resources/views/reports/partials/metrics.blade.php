{{-- $metrics: key => [title, help]; values in $data['metrics']. --}}
<div class="rpt-metrics">
    @foreach ($metrics as $key => [$title, $help])
        @php
            $metric = $data['metrics'][$key];
        @endphp
        <x-fruit::card class="rpt-metric">
            <div class="rpt-metric-title">
                {{ $title }}
                @if ($help)
                    <x-fruit::tooltip :text="$help" text-id="rpt-help-{{ $key }}">
                        <button type="button" class="f-button f-button--ghost f-button--icon" aria-label="{{ $title }}" aria-describedby="rpt-help-{{ $key }}"><x-heroicon-o-information-circle class="f-icon" aria-hidden="true" /></button>
                    </x-fruit::tooltip>
                @endif
            </div>
            <div class="rpt-metric-value">{{ $metric['value'] ?? '–' }}@include('reports/partials/change', ['change' => $metric['change']])</div>
        </x-fruit::card>
    @endforeach
</div>
