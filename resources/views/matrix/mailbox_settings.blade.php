@extends('layouts.app')
@section('page_width', 'narrow')
@section('title_full', 'Matrix - '.$mailbox->name)
@section('main_class', 'fruit-ui')
@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection
@section('content')
    <div class="page-content">
        <livewire:matrix-settings :mailbox_id="$mailbox->id" />
    </div>
@endsection
