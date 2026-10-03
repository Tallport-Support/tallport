@php
    $report_filters = request()->only(['period', 'from', 'to', 'mailbox', 'type']);
@endphp
<x-page-nav :label="__('Reports')">
    <x-slot:title><h1>{{ __('Reports') }}</h1></x-slot:title>
    <a href="{{ route('reports.conversations', $report_filters) }}" @if (Route::is('reports.conversations')) aria-current="page" @endif>{{ __('Conversations') }}</a>
    <a href="{{ route('reports.productivity', $report_filters) }}" @if (Route::is('reports.productivity')) aria-current="page" @endif>{{ __('Productivity') }}</a>
</x-page-nav>
