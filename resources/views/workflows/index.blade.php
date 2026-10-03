@extends('layouts.app')

@section('title_full', __('Workflows').' - '.($mailbox ? $mailbox->name : __('All Mailboxes')))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @if ($mailbox && !Auth::user()->isAdmin())
        @include('mailboxes/sidebar_menu')
    @else
        @include('workflows/sidebar_menu')
    @endif
@endsection

@section('content')
    <div class="section-heading">
        {{ __('Workflows') }}@if ($mailbox) <small>{{ $mailbox->name }}</small>@else <small>{{ __('All Mailboxes') }}</small>@endif
        <a href="{{ $mailbox ? route('mailboxes.workflows.create', ['mailbox_id' => $mailbox->id]) : route('workflows.create') }}" class="btn btn-bordered margin-left-10">{{ __('New Workflow') }}</a>
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        @if ($mailbox && $global)
            <p class="text-help margin-top">{{ __(':count workflows of all mailboxes run first.', ['count' => $global]) }}@if (Auth::user()->isAdmin()) <a href="{{ route('workflows') }}">{{ __('All Mailboxes') }}</a>@endif</p>
        @endif
        @if (!count($automatic) && !count($manual))
            @include('partials/empty', ['icon' => 'random', 'empty_text' => __('Workflows act on conversations by themselves: when a new one comes in, someone replies, or a customer has waited too long. Manual workflows run from a conversation\'s menu.')])
        @else
            @foreach ([__('Automatic') => $automatic, __('Manual') => $manual] as $list_title => $list)
                @if (count($list))
                    <h4 class="margin-top">{{ $list_title }}</h4>
                    <ul class="workflows-list" data-mailbox_id="{{ $mailbox ? $mailbox->id : '' }}">
                        @foreach ($list as $workflow)
                            <li class="workflow-item" data-workflow-id="{{ $workflow->id }}">
                                <i class="glyphicon glyphicon-menu-hamburger workflow-handle" title="{{ __('Drag to change the order') }}"></i>
                                <a href="{{ $workflow->url() }}">{{ $workflow->name }}</a>
                                @if (!$workflow->complete)<span class="label label-warning">{{ __('Incomplete') }}</span>@elseif (!$workflow->active)<span class="label label-default">{{ __('Inactive') }}</span>@endif
                                @if ($workflow->isAutomatic())<small class="text-help">{{ __('Ran on :count conversations', ['count' => $workflow->conversationsCount()]) }}</small>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
            <p class="text-help">{{ __('Workflows run in this order. Drag to change it.') }}</p>
        @endif
    </div>
@endsection

@section('javascripts')
    @parent
    <script src="{{ asset('js/html5sortable.js') }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection

@section('javascript')
    @parent
    workflowsListInit();
@endsection
