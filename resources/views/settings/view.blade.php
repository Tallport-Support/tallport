@extends('layouts.app')

@section('title_full', __('Settings').' - '.$section_name)

@section('page_width', $section == 'api' ? 'medium' : 'narrow')

@section('sidebar')
    {{-- The sections are in the app's sidebar (partials/app_sidebar_settings). --}}
    <x-page-nav :label="__('Settings')">
        <x-slot:title><h1>{{ $section_name }}</h1></x-slot:title>
    </x-page-nav>
@endsection

{{-- Converted to FruitUI: the whole main area is in its scope. --}}
@section('main_class', 'fruit-ui')

@section('content')
    <div class="fruit-ui page-content">
        @include('partials/flash_messages')
        @include(\Eventy::filter('settings.view', 'settings/'.$section, $section))
    </div>
@endsection
