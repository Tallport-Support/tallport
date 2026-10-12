@extends('layouts.app')

@section('title', __('Manage Mailboxes'))

{{-- Settings › Mailboxes, a level of its own (layouts/app): a mailbox's row opens it beside it. --}}
@section('drilldown')
    @include('mailboxes/columns/mailboxes')
@endsection
