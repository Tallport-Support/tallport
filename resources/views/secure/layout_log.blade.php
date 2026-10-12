@extends('layouts.app')

{{-- A log as a level beside the Logs (layouts/app): its 'log_name' row current there, its 'content'
     (its bar with filters, and its entries) wide. --}}
@section('title', __('Logs'))

@section('drilldown')
    @php $log_name = trim($__env->yieldContent('log_name')); @endphp
    @include('secure/columns/logs')
    <x-fruit::drilldown-column :title="App\ActivityLog::getLogTitle($log_name)" id="log">
        <x-slot:back><x-fruit::back-link :href="route('logs')" wire:navigate>{{ __('Logs') }}</x-fruit::back-link></x-slot:back>
        <x-fruit::page width="wide">
            @yield('content')
        </x-fruit::page>
    </x-fruit::drilldown-column>
@endsection
