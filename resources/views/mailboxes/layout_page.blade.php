@extends('layouts.app')

{{-- A mailbox's settings page, in the mailbox (mailboxes/sidebar_menu: the list of its pages beside it,
     and its title): its 'content' section, a narrow page unless it says otherwise (page_width), with its
     own Save button kept in view (page_footer). --}}
@section('title', (App\Misc\MailboxSettings::currentTitle($mailbox, Auth::user(), Route::currentRouteName()) ?? __('General')).' - '.$mailbox->name)

@section('page_width', 'narrow')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection
