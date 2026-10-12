@extends('layouts/level')

@section('page_width', 'narrow')

@section('title', __('Status'))

@section('content')
<livewire:system-status />

@action('system.status.after_content')

@endsection
