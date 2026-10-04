@extends('layouts.app')

@section('title', __('Modules'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('modules/sidebar_menu')
@endsection

@section('content')
<div class="page-content">

    @include('partials/flash_messages')

    @if (!count($installed_modules))
        <p class="f-muted" id="installed">{{ __('No modules are installed.') }}</p>
    @else
        <div class="page-toolbar f-row" id="installed">
            <h2 class="f-title-3">{{ __('Installed Modules') }} <span class="f-muted">({{ count($installed_modules) }})</span></h2>
            <div class="f-row">
                <a href="#" data-trigger="modal" data-modal-body="#deactivate_license_modal" data-modal-size="sm" data-modal-no-footer="true" data-modal-title="{{ __('Deactivate License') }}" data-modal-on-show="deactivateLicenseModal" class="f-button f-button--ghost f-button--small">{{ __('Deactivate License') }}</a>
                <a href="https://freescout.net/remind-license-keys/" target="_blank" class="f-button f-button--ghost f-button--small">{{ __('Remind License Keys') }}</a>
            </div>
        </div>

        @if ($updates_available)
            <div class="f-alert f-alert--warning modules-updates">
                <div class="f-alert__body">
                    {{ __('There are updates available') }}:
                    <ul id="new_versions_list">
                        @php
                            $new_v_counter = 0;
                        @endphp
                        @foreach ($installed_modules as $module)
                            @if (!empty($module['new_version']))
                                @php $new_v_counter++; @endphp
                                <li><a href="#module-{{ $module['alias'] }}" data-module-alias="{{ $module['alias'] }}">{{ $module['name']}} ({{ $module['new_version'] }})</a></li>
                            @endif
                        @endforeach
                    </ul>
                </div>
                @if ($new_v_counter)
                    <div class="f-alert__actions"><a href="" class="f-button f-button--small update-all-trigger" data-loading-text="{{ __('Update Now') }} ({{ $new_v_counter }})…">{{ __('Update Now') }} ({{ $new_v_counter }})</a></div>
                @endif
            </div>
        @endif

        @if ($invalid_symlinks)
            @include('modules/partials/invalid_symlinks')
        @endif

        <div class="modules-list">
            @foreach ($installed_modules as $module)
                @include('modules/partials/module_card')
            @endforeach
        </div>
    @endif
</div>

    <div id="deactivate_license_modal" class="hidden">
        <div class="f-stack modal-form">
            <select class="f-input deactivate-license-module" aria-label="{{ __('Modules') }}">
                @foreach ($all_modules as $module_alias => $module_name)
                    <option value="{{ $module_alias }}">{{ App\Module::formatName($module_name) }}</option>
                @endforeach
            </select>
            <input type="text" class="f-input deactivate-license-key" placeholder="{{ __('License Key') }}" aria-label="{{ __('License Key') }}" />
            <div class="modal-form__actions">
                <button class="f-button f-button--ghost" data-dismiss="modal">{{ __('Cancel') }}</button>
                <button class="f-button f-button--primary button-deactivate-license" data-loading-text="{{ __('Deactivate') }}…">{{ __('Deactivate') }}</button>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    @parent
    initModulesList();
@endsection