@extends('layouts.app')

@section('title_full', __('Settings').' - '.$section_name)

@section('page_width', $section == 'api' ? 'medium' : 'narrow')

@section('sidebar')
    <x-page-nav :label="__('Settings')">
        <x-slot:title><h1>{{ __('Settings') }}</h1></x-slot:title>
        @foreach ($sections as $item_name => $item_info)
            <a href="{{ route('settings', ['section' => $item_name]) }}" @if ($item_name == $section) aria-current="page" @endif>{{ $item_info['title'] }}</a>
        @endforeach
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
