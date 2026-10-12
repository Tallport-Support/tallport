@extends('layouts.app')

@section('title', isset($module) ? App\Module::formatName($module['name']) : __('Modules'))

{{-- Settings › Modules, and a module beside them (layouts/app). --}}
@section('drilldown')
    @include('modules/columns/modules')
    @isset($module)
        @include('modules/columns/module')
    @endisset
@endsection
