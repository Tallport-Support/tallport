@extends('layouts.app')

@section('title', __('(no subject)'))
@section('body_class', 'body-conv')
@if (!empty($conversation->id))
    @section('body_attrs')@parent data-conversation_id="{{ $conversation->id }}"@endsection
@endif


@section('content')
    @include('partials/flash_messages')

    <livewire:new-conversation
        :conversation="$conversation"
        :mailbox="$mailbox"
        :thread="$thread ?? $conversation->threads()->first()"
        :to="$to ?? []"
        :name="$name ?? []"
        :phone="$phone ?? ''"
        :to-email="$to_email ?? []"
        :attachments="$attachments ?? []"
        :from-aliases="$from_aliases ?? []"
        :from-alias="$from_alias ?? ''"
        :after-send="$after_send ?? null"
    />
    @action('new_conversation_form.after', $conversation)
@endsection
