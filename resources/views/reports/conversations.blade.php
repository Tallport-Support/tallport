@extends('layouts.app')

@section('title', __('Conversations Report'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('reports/sidebar_menu')
@endsection

@section('content')
    <div class="page-content rpt-report">
        @include('reports/partials/filters')

        <livewire:report-results :name="$name" :query="request()->query()" />
    </div>
@endsection
