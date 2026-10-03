@extends('layouts.app')

@section('title_full', __('Workflows').' - '.($mailbox ? $mailbox->name : __('All Mailboxes')))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @if ($mailbox && !Auth::user()->isAdmin())
        @include('mailboxes/sidebar_menu')
    @else
        @include('workflows/sidebar_menu')
    @endif
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <div class="page-toolbar f-row">
            <h2 class="f-title-3 workflow-section-title">{{ $mailbox ? $mailbox->name : __('All Mailboxes') }}</h2>
            <a href="{{ $mailbox ? route('mailboxes.workflows.create', ['mailbox_id' => $mailbox->id]) : route('workflows.create') }}" class="f-button">{{ __('New Workflow') }}</a>
        </div>

        @if ($mailbox && $global)
            <p class="f-help">{{ __(':count workflows of all mailboxes run first.', ['count' => $global]) }}@if (Auth::user()->isAdmin()) <a href="{{ route('workflows') }}">{{ __('All Mailboxes') }}</a>@endif</p>
        @endif
        @if (!count($automatic) && !count($manual))
            <x-fruit::empty-state>
                <x-slot:icon><x-heroicon-o-arrows-right-left /></x-slot:icon>
                {{ __('Workflows act on conversations by themselves: when a new one comes in, someone replies, or a customer has waited too long. Manual workflows run from a conversation\'s menu.') }}
            </x-fruit::empty-state>
        @else
            @foreach ([__('Automatic') => $automatic, __('Manual') => $manual] as $list_title => $list)
                @if (count($list))
                    <h3 class="f-title-3 workflow-section-title">{{ $list_title }}</h3>
                    <ul class="workflows-list" data-mailbox_id="{{ $mailbox ? $mailbox->id : '' }}">
                        @foreach ($list as $workflow)
                            <li class="workflow-item" data-workflow-id="{{ $workflow->id }}">
                                <x-heroicon-o-bars-3 class="f-icon workflow-handle" aria-hidden="true" title="{{ __('Drag to change the order') }}" />
                                <a href="{{ $workflow->url() }}">{{ $workflow->name }}</a>
                                @if (!$workflow->complete)<x-fruit::badge tone="warning">{{ __('Incomplete') }}</x-fruit::badge>@elseif (!$workflow->active)<x-fruit::badge>{{ __('Inactive') }}</x-fruit::badge>@endif
                                @if ($workflow->isAutomatic())<small class="f-muted workflow-item__meta">{{ __('Ran on :count conversations', ['count' => $workflow->conversationsCount()]) }}</small>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
            <p class="f-help">{{ __('Workflows run in this order. Drag to change it.') }}</p>
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
