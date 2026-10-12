{{-- A module as a drill-down level beside the Modules (layouts/app): what it is, what it needs, its new
     version, and activating, deactivating or deleting it. --}}
@php
    // It can run here: the app version and PHP extensions it needs.
    $module_runs = (empty($module['requiredAppVersion']) || \Helper::checkAppVersion($module['requiredAppVersion'])) && empty($module['requiredPhpExtensionsMissing']);
@endphp
<x-fruit::drilldown-column :title="App\Module::formatName($module['name'])" id="module">
    <x-slot:back><x-fruit::back-link :href="route('modules')" wire:navigate>{{ __('Modules') }}</x-fruit::back-link></x-slot:back>
    <x-fruit::page width="narrow" x-data="tallportModules" data-alias="{{ $module['alias'] }}" data-modules-url="{{ route('modules') }}">
        <x-fruit::form-section>
            <div class="f-form-row">
                <span class="users-row__person">
                    <x-fruit::avatar :src="$module['img'] ?: asset(App\Module::IMG_DEFAULT)" class="module-row__img" />
                    <span class="users-row__name"><span>{{ App\Module::formatName($module['name']) }}</span><small class="f-help">{{ $module['description'] }}</small></span>
                </span>
                <span class="f-form-row__value">@if (empty($module['installed'])){{ __('Not Installed') }}@elseif (empty($module['active'])){{ __('Inactive') }}@else{{ __('Active') }}@endif</span>
            </div>
            <div class="f-form-row"><span>{{ __('Version') }}</span><span class="f-form-row__value">{{ $module['version'] }}</span></div>
            @if (!empty($module['author']))
                <div class="f-form-row"><span>{{ __('Author') }}</span>@if (!empty($module['authorUrl']))<a href="{{ $module['authorUrl'] }}" target="_blank" rel="noopener">{{ $module['author'] }}</a>@else<span class="f-form-row__value">{{ $module['author'] }}</span>@endif</div>
            @endif
            @if (!empty($module['detailsUrl']))
                <a class="f-form-row f-form-row--link" href="{{ $module['detailsUrl'] }}" target="_blank" rel="noopener"><span>{{ __('View details') }}</span></a>
            @endif
        </x-fruit::form-section>

        {{-- What it needs that's missing. --}}
        @if (!empty($module['requiredAppVersion']) && !\Helper::checkAppVersion($module['requiredAppVersion']))
            <p class="f-error">{{ __('Required :app_name version', ['app_name' => \Config::get('app.name')]) }}: <strong>{{ $module['requiredAppVersion'] }}</strong></p>
        @endif
        @if (!empty($module['requiredPhpExtensionsMissing']))
            <p class="f-error">{{ __('Required PHP extensions') }}: <strong>{{ implode(', ', $module['requiredPhpExtensionsMissing']) }}</strong></p>
        @endif
        @if (!empty($module['requiredModulesMissing']))
            <p class="f-error">{{ __('Required Modules') }}: @foreach ($module['requiredModulesMissing'] as $missing_module => $missing_version)<strong>{{ $missing_module }} ({{ $missing_version }})</strong> @endforeach</p>
        @endif

        {{-- Its new version, once App\Livewire\ModuleUpdates has checked (on the Modules beside it). --}}
        <div class="f-alert f-alert--warning alert-module-update" x-data="{ new_version: '' }" x-on:module-updates.window="new_version = $event.detail.versions[@js($module['alias'])] || ''" x-show="new_version" x-cloak>
            <div class="f-alert__body">{{ __('A new version is available') }}: <strong x-text="new_version"></strong>@if (!empty($module['detailsUrl'])) (<a href="{{ $module['detailsUrl'] }}?changelog=1" target="_blank">{{ __('View details') }}</a>)@endif</div>
            <div class="f-alert__actions"><button type="button" class="f-button f-button--small update-module-trigger" x-on:click="action($event, 'update')">{{ __('Update Now') }}</button></div>
        </div>

        @if ($module_runs || !empty($module['active']))
            <x-fruit::form-section>
                <div class="f-form-row">
                    @if (!empty($module['active']))
                        <button type="button" class="f-button deactivate-trigger" x-on:click="action($event, 'deactivate')">{{ __('Deactivate') }}</button>
                    @else
                        <button type="button" class="f-button f-button--primary activate-trigger" x-on:click="action($event, 'activate')">{{ __('Activate') }}</button>
                    @endif
                </div>
            </x-fruit::form-section>
        @endif

        @if (!empty($module['installed']) && empty($module['active']))
            <x-fruit::form-section :title="__('Danger Zone')" :level="3">
                <div class="f-form-row">
                    <button type="button" class="f-button f-button--danger delete-module-trigger" x-on:click="remove">{{ __('Delete') }}</button>
                </div>
            </x-fruit::form-section>
        @endif
    </x-fruit::page>
</x-fruit::drilldown-column>
