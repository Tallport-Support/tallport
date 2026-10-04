@php
    $route_name = Route::currentRouteName();
@endphp
<nav id="connection-settings" class="f-section-nav connection-nav" aria-label="{{ __('Connection Settings') }}">
    <a href="{{ route('mailboxes.connection', ['id'=>$mailbox->id]) }}" @if ($route_name == 'mailboxes.connection') aria-current="page" @endif>@if ($route_name != 'mailboxes.connection' && !$mailbox->isOutActive())<x-icon.triangle-alert class="f-icon connection-nav__warning" aria-hidden="true" /> @endif{{ __('Sending Emails') }}</a>
    <a href="{{ route('mailboxes.connection.incoming', ['id'=>$mailbox->id]) }}" @if ($route_name == 'mailboxes.connection.incoming') aria-current="page" @endif>@if ($route_name != 'mailboxes.connection.incoming' && !$mailbox->isInActive())<x-icon.triangle-alert class="f-icon connection-nav__warning" aria-hidden="true" /> @endif{{ __('Fetching Emails') }}</a>
</nav>
