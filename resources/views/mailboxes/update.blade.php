@extends('layouts.app')

@section('title', $mailbox->name)

@section('title_full', __('Edit Mailbox').' - '.$mailbox->name)

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

{{-- Settings › Mailboxes › the mailbox (layouts/app). --}}
@section('drilldown')
    @include('mailboxes/columns/mailboxes')
    @include('mailboxes/columns/mailbox')
@endsection
