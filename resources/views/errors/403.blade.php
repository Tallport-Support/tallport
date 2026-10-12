@extends('errors::layout')

@section('title', 'Access denied')

@section('message')
    {{ __('Access denied') }}
@endsection

@php
    $msg = $exception->getMessage();
@endphp
@if ($msg && strstr($msg, '[display]'))
    @section('details', str_replace('[display]', '', $msg))
@endif

@section('actions')
    {{ __('Go to') }} <a href="{{ \Helper::urlHome() }}">{{ __('Homepage') }}</a>
@endsection
