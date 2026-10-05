<x-fruit::card class="module-card {{ !empty($module['active']) ? 'active' : (empty($module['installed']) ? 'not-installed' : '') }}" id="module-{{ $module['alias'] }}" data-alias="{{ $module['alias'] }}">
	@if (!empty($module['img']))
		<img src="{{ $module['img'] }}" alt="" />
	@else
		<img src="{{ asset(App\Module::IMG_DEFAULT) }}" alt="" />
	@endif
	<div class="module-wrap">
	    <h2 class="f-title-3 module-card__title">{{ App\Module::formatName($module['name']) }}@if (empty($module['installed'])) <x-fruit::badge>{{ __('Not Installed') }}</x-fruit::badge>@elseif (empty($module['active'])) <x-fruit::badge>{{ __('Inactive') }}</x-fruit::badge>@else <x-fruit::badge tone="success">{{ __('Active') }}</x-fruit::badge>@endif</h2>
	    <p>
	    	{{ $module['description'] }}
	    </p>
	    <div class="module-details">
		    <span>{{ __('Version') }}: {{ $module['version'] }}</span>
            @if (!empty($module['author']))
                @if(!empty($module['authorUrl']))
                    · <a href="{{ $module['authorUrl'] }}" target="_blank">{{$module['author']}}</a>
                @else
                    · {{$module['author']}}
                @endif
            @endif
		    @if (!empty($module['detailsUrl']))
		    	· <a href="{{ $module['detailsUrl'] }}" target="_blank">{{ __('View details') }}</a>
		    @endif
		    @if (!empty($module['requiredAppVersion']) && !\Helper::checkAppVersion($module['requiredAppVersion']))
		    	@php
		    		$wrong_app_verion = true;
		    	@endphp
		    	· <span class="f-error nowrap">{{ __('Required :app_name version', ['app_name' => \Config::get('app.name')]) }}: <strong>{{ $module['requiredAppVersion'] }}</strong></span>
		    @endif
		    @if (!empty($module['requiredPhpExtensionsMissing']))
		    	· <span class="f-error nowrap">{{ __('Required PHP extensions') }}: <strong>{{ implode(', ', $module['requiredPhpExtensionsMissing']) }}</strong></span>
		    @endif
		    @if (!empty($module['requiredModulesMissing']))
		    	· <span class="f-error nowrap">{{ __('Required Modules') }}: @foreach ($module['requiredModulesMissing'] as $missing_module => $missing_version)<strong>{{ $missing_module }} ({{ $missing_version }})</strong>@endforeach</span>
		    @endif
		</div>
		<div class="module-actions f-row">
			@if ((empty($wrong_app_verion) && empty($module['requiredPhpExtensionsMissing'])) || !empty($module['active']))
				@if (!empty($module['active']))
					<button type="button" class="f-button deactivate-trigger" x-on:click="action($event, 'deactivate')">{{ __('Deactivate') }}</button>
				@else
					<button type="button" class="f-button f-button--primary activate-trigger" x-on:click="action($event, 'activate')">{{ __('Activate') }}</button>
				@endif
			@endif

			@if (!empty($module['installed']) && empty($module['active']))
				<button type="button" class="f-button f-button--danger delete-module-trigger" x-on:click="remove">{{ __('Delete') }}</button>
			@endif
		</div>
		{{-- Its new version, once App\Livewire\ModuleUpdates has checked. --}}
		<div class="f-alert f-alert--warning alert-module-update" x-data="{ new_version: '' }" x-on:module-updates.window="new_version = $event.detail.versions[@js($module['alias'])] || ''" x-show="new_version" x-cloak>
			<div class="f-alert__body">{{ __('A new version is available') }}: <strong x-text="new_version"></strong> (<a href="{{ $module['detailsUrl'] }}?changelog=1" target="_blank">{{ __('View details') }}</a>)</div>
			<div class="f-alert__actions"><button type="button" class="f-button f-button--small update-module-trigger" x-on:click="action($event, 'update')">{{ __('Update Now') }}</button></div>
		</div>
	</div>
</x-fruit::card>
