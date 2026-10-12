{{-- Manage › Logs as a drill-down level (layouts/app): a row per log, the open one ($log_name) current. --}}
<x-fruit::drilldown-column :title="__('Logs')" id="logs">
    <x-slot:back><x-fruit::back-link :href="route('settings')" wire:navigate>{{ __('Settings') }}</x-fruit::back-link></x-slot:back>
    <x-fruit::page width="narrow">
        <x-fruit::form-section :aria-label="__('Logs')">
            @foreach (App\ActivityLog::menuNames() as $logs_item)
                <a class="f-form-row f-form-row--link" href="{{ App\ActivityLog::logUrl($logs_item) }}" wire:navigate @if ($logs_item == $log_name) aria-current="page" @endif><span>{{ App\ActivityLog::getLogTitle($logs_item) }}</span></a>
            @endforeach
        </x-fruit::form-section>
    </x-fruit::page>
</x-fruit::drilldown-column>
