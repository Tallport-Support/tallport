{{-- Settings › Modules as a drill-down level (layouts/app): new versions, then a row per module with
     its state, the open one ($module) current. --}}
<x-fruit::drilldown-column :title="__('Modules')" id="modules">
    <x-slot:back><x-fruit::back-link :href="route('settings')" wire:navigate>{{ __('Settings') }}</x-fruit::back-link></x-slot:back>
    <x-fruit::page width="narrow" x-data="tallportModules">
        @include('partials/flash_messages')

        @if (!count($installed_modules))
            <x-fruit::empty-state><x-slot:title>{{ __('No modules are installed.') }}</x-slot:title></x-fruit::empty-state>
        @else
            {{-- New versions are checked once the page is open. --}}
            <livewire:module-updates lazy />

            @if ($invalid_symlinks)
                @include('modules/partials/invalid_symlinks')
            @endif

            <x-fruit::form-section :title="__('Installed Modules')" :level="3">
                @foreach ($installed_modules as $modules_item)
                    <a class="f-form-row f-form-row--link" href="{{ route('modules.module', ['alias' => $modules_item['alias']]) }}" wire:navigate @if (($module['alias'] ?? null) == $modules_item['alias']) aria-current="page" @endif>
                        <span class="users-row__person">
                            <x-fruit::avatar :src="$modules_item['img'] ?: asset(App\Module::IMG_DEFAULT)" class="module-row__img" />
                            <span class="users-row__name"><span>{{ App\Module::formatName($modules_item['name']) }}</span><small class="f-help">{{ __('Version') }} {{ $modules_item['version'] }}</small></span>
                        </span>
                        <span class="f-form-row__value">@if (empty($modules_item['installed'])){{ __('Not Installed') }}@elseif (empty($modules_item['active'])){{ __('Inactive') }}@else{{ __('Active') }}@endif</span>
                    </a>
                @endforeach
            </x-fruit::form-section>
        @endif

    </x-fruit::page>
</x-fruit::drilldown-column>
