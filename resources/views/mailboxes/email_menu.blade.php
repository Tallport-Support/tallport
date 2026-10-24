{{-- A mailbox's Email, a channel like the others: the tabs the user may open (App\Misc\MailboxSettings::emailTabs()),
     the open one current; Sending and Fetching say so when they need attention. --}}
@php
    $email_route = Route::currentRouteName();
    $email_attention = ['mailboxes.connection' => !$mailbox->isOutActive(), 'mailboxes.connection.incoming' => !$mailbox->isInActive()];
@endphp
<nav id="connection-settings" class="f-section-nav connection-nav" aria-label="{{ __('Email') }}">
    @foreach (App\Misc\MailboxSettings::emailTabs($mailbox, Auth::user()) as $email_tab_route => $email_tab_label)
        <a wire:navigate href="{{ route($email_tab_route, ['id' => $mailbox->getSlug()]) }}" @if ($email_route == $email_tab_route) aria-current="page" @endif>@if ($email_route != $email_tab_route && !empty($email_attention[$email_tab_route]))<x-icon.triangle-alert class="f-icon connection-nav__warning" aria-hidden="true" />{{ $email_tab_label }}<span class="f-sr-only">, {{ __('needs attention') }}</span>@else{{ $email_tab_label }}@endif</a>
    @endforeach
</nav>
