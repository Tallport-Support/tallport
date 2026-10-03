@extends('layouts.app')

@section('title_full', __('Settings').' - '.$section_name)

@section('sidebar')
    <x-page-nav :label="__('Settings')">
        <x-slot:title><h1>{{ __('Settings') }}</h1></x-slot:title>
        @foreach ($sections as $item_name => $item_info)
            <a href="{{ route('settings', ['section' => $item_name]) }}" @if ($item_name == $section) aria-current="page" @endif>{{ $item_info['title'] }}</a>
        @endforeach
    </x-page-nav>
@endsection

@section('content')
    <div class="section-heading">
        {{ $section_name }}
    </div>

    @include('partials/flash_messages')

    <div class="row-container form-container">
        <div class="row">
            <div class="col-xs-12">
                @include(\Eventy::filter('settings.view', 'settings/'.$section, $section))
            </div>
        </div>
    </div>

@endsection
