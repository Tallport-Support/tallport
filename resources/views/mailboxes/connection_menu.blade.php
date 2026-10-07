@php
    $route_name = Route::currentRouteName();
@endphp
<nav id="connection-settings" class="f-section-nav connection-nav" aria-label="{{ __('Connection Settings') }}">
    <a wire:navigate href="{{ route('mailboxes.connection', ['id'=>$mailbox->id]) }}" @if ($route_name == 'mailboxes.connection') aria-current="page" @endif>@if ($route_name != 'mailboxes.connection' && !$mailbox->isOutActive())<x-icon.triangle-alert class="f-icon connection-nav__warning" aria-hidden="true" />{{ __('Sending Emails') }}<span class="f-sr-only">, {{ __('needs attention') }}</span>@else{{ __('Sending Emails') }}@endif</a>
    <a wire:navigate href="{{ route('mailboxes.connection.incoming', ['id'=>$mailbox->id]) }}" @if ($route_name == 'mailboxes.connection.incoming') aria-current="page" @endif>@if ($route_name != 'mailboxes.connection.incoming' && !$mailbox->isInActive())<x-icon.triangle-alert class="f-icon connection-nav__warning" aria-hidden="true" />{{ __('Fetching Emails') }}<span class="f-sr-only">, {{ __('needs attention') }}</span>@else{{ __('Fetching Emails') }}@endif</a>
</nav>
