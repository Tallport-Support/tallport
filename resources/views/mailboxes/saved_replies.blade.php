@extends('layouts.app')

@section('title_full', __('Saved Replies').' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/saved_replies_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <div class="page-toolbar f-row">
            <h2 class="f-title-3">{{ __('Saved Replies') }}</h2>
            <a href="{{ route('mailboxes.saved_replies.create', ['id' => $mailbox->id]) }}" class="f-button">{{ __('New Saved Reply') }}</a>
        </div>

        @if (!count($tree))
            <x-fruit::empty-state>
                <x-slot:icon><x-icon.message-square-more /></x-slot:icon>
                {{ __('Saved replies are texts (with files) that agents put in a reply in a click. Variables like the customer\'s name are filled in.') }}
            </x-fruit::empty-state>
        @else
            <p class="f-help">{{ __('Drag to change the order. A saved reply with others under it is a category.') }}</p>
            <ul class="saved-replies-list" x-data="tallportSavedRepliesOrder({{ $mailbox->id }})">
                @foreach ($tree as [$saved_reply, $depth, $has_children])
                    <li class="saved-reply-item" data-saved-reply-id="{{ $saved_reply->id }}" data-parent-id="{{ (int) $saved_reply->parent_saved_reply_id }}" style="margin-inline-start: {{ $depth * 24 }}px">
                        <x-icon.menu class="f-icon saved-reply-handle" aria-hidden="true" title="{{ __('Drag to change the order') }}" />
                        @if ($has_children)<x-icon.folder-open class="f-icon" aria-hidden="true" />@endif
                        <a href="{{ route('mailboxes.saved_replies.edit', ['id' => $mailbox->id, 'saved_reply_id' => $saved_reply->id]) }}">{{ $saved_reply->name }}</a>
                        @if ($saved_reply->global)<x-fruit::badge title="{{ __('Available in every mailbox') }}">{{ __('Global') }}</x-fruit::badge>@endif
                        @if ($saved_reply->auto_load)<x-fruit::badge tone="accent">{{ __('Default reply') }}</x-fruit::badge>@endif
                        @if ($saved_reply->attachments)<x-icon.paperclip class="f-icon" aria-hidden="true" />@endif
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
