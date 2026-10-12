@extends('layouts.app')

@section('title', __('Manage Users'))

{{-- Settings › Users, a level of its own (layouts/app): a person's row opens them beside it. --}}
@section('drilldown')
    @include('users/columns/users', ['user' => null])
@endsection
