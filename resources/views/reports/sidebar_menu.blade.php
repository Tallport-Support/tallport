@php
    $report_filters = request()->only(['period', 'from', 'to', 'mailbox', 'type']);
@endphp
<div class="sidebar-title">
    {{ __('Reports') }}
</div>
<ul class="sidebar-menu">
    <li @if (Route::is('reports.conversations'))class="active"@endif><a href="{{ route('reports.conversations', $report_filters) }}"><i class="glyphicon glyphicon-envelope"></i> {{ __('Conversations') }}</a></li>
    <li @if (Route::is('reports.productivity'))class="active"@endif><a href="{{ route('reports.productivity', $report_filters) }}"><i class="glyphicon glyphicon-signal"></i> {{ __('Productivity') }}</a></li>
</ul>
