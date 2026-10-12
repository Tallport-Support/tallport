@extends('mailboxes/layout_page')
@section('page_width', 'narrow')
@section('title_full', 'Matrix - '.$mailbox->name)
@section('content')
    <div class="page-content">
        <livewire:matrix-settings :mailbox_id="$mailbox->id" />
    </div>
@endsection
