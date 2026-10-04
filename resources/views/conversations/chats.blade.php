@extends('layouts.app')

@section('title', __('Chats').' - '.$mailbox->name)

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

@section('body_class', 'chat-mode')

@section('main_class', 'fruit-ui')

@section('list')
    @include('mailboxes/sidebar_menu_view')
@endsection

@section('content')
    <x-fruit::empty-state class="split-view__empty">
        <x-slot:icon><x-icon.messages-square /></x-slot:icon>
        <x-slot:title>{{ __('No conversation selected') }}</x-slot:title>
        {{ __('Choose a conversation from the list.') }}
    </x-fruit::empty-state>
@endsection
