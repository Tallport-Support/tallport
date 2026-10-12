@extends('errors::layout')

@section('title', 'Page Not Found')

@section('message')
    {{ __('Sorry, the page you are looking for could not be found.') }}
@endsection

@section('actions')
    {{ __('Go to') }} <a href="{{ \Helper::urlHome() }}">{{ __('Homepage') }}</a>
@endsection
