@extends('mailboxes/layout_page')
@section('page_width', 'narrow')
@section('title_full', 'Matrix - '.$mailbox->name)
@section('content')
    <div class="page-content">
        <livewire:matrix-settings :mailbox_id="$mailbox->id" />
        {{-- What the bot says by itself: the mailbox's greeting (when it joins a chat) and auto reply, or its own. --}}
        @if (App\Matrix\MatrixMailbox::forMailbox($mailbox->id))
            @include('mailboxes/partials/message_links', ['key' => 'matrix'])
        @endif
    </div>
@endsection
