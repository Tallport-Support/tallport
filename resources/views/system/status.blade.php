@extends('layouts.app')

@section('page_width', 'medium')

@section('title', __('System Status'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('system/sidebar_menu')
@endsection

@section('content')
<livewire:system-status />

@action('system.status.after_content')

@endsection
