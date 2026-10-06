@extends('layouts.app')

@section('page_width', 'narrow')

@section('title', __('Status'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav :label="__('Status')">
        <x-slot:title><h1>{{ __('Status') }}</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
<livewire:system-status />

@action('system.status.after_content')

@endsection
