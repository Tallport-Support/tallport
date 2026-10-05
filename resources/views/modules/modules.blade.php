@extends('layouts.app')

@section('title', __('Modules'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('modules/sidebar_menu')
@endsection

@section('content')
<div class="page-content" x-data="tallportModules">

    @include('partials/flash_messages')

    @if (!count($installed_modules))
        <p class="f-muted" id="installed">{{ __('No modules are installed.') }}</p>
    @else
        <div class="page-toolbar f-row" id="installed">
            <h2 class="f-title-3">{{ __('Installed Modules') }} <span class="f-muted">({{ count($installed_modules) }})</span></h2>
        </div>

        {{-- New versions are checked once the page is open. --}}
        <livewire:module-updates lazy />

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

@endsection
