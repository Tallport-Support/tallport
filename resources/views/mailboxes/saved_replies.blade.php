@extends('layouts.app')

@section('title_full', __('Saved Replies').' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        {{ __('Saved Replies') }}
        <a href="{{ route('mailboxes.saved_replies.create', ['id' => $mailbox->id]) }}" class="btn btn-bordered margin-left-10">{{ __('New Saved Reply') }}</a>
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        @if (!count($tree))
            @include('partials/empty', ['icon' => 'comment', 'empty_text' => __('Saved replies are texts (with files) that agents put in a reply in a click. Variables like the customer\'s name are filled in.')])
        @else
            <p class="text-help margin-top">{{ __('Drag to change the order. A saved reply with others under it is a category.') }}</p>
            <ul class="saved-replies-list" data-mailbox_id="{{ $mailbox->id }}">
                @foreach ($tree as [$saved_reply, $depth, $has_children])
                    <li class="saved-reply-item" data-saved-reply-id="{{ $saved_reply->id }}" data-parent-id="{{ (int) $saved_reply->parent_saved_reply_id }}" style="margin-left: {{ $depth * 24 }}px">
                        <i class="glyphicon glyphicon-menu-hamburger saved-reply-handle" title="{{ __('Drag to change the order') }}"></i>
                        @if ($has_children)<i class="glyphicon glyphicon-folder-open text-help"></i>@endif
                        <a href="{{ route('mailboxes.saved_replies.edit', ['id' => $mailbox->id, 'saved_reply_id' => $saved_reply->id]) }}">{{ $saved_reply->name }}</a>
                        @if ($saved_reply->global)<span class="label label-default" title="{{ __('Available in every mailbox') }}">{{ __('Global') }}</span>@endif
                        @if ($saved_reply->auto_load)<span class="label label-info">{{ __('Default reply') }}</span>@endif
                        @if ($saved_reply->attachments)<i class="glyphicon glyphicon-paperclip text-help"></i>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection

@section('javascripts')
    @parent
    <script src="{{ asset('js/html5sortable.js') }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection

@section('javascript')
    @parent
    savedRepliesListInit();
@endsection
