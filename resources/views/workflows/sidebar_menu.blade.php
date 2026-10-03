{{-- Workflows of all mailboxes and of each mailbox (admins). --}}
<x-page-nav :label="__('Workflows')">
    <x-slot:title><h1>{{ __('Workflows') }}</h1></x-slot:title>
    <a href="{{ route('workflows') }}" @if (!$mailbox) aria-current="page" @endif>{{ __('All Mailboxes') }}</a>
    @foreach (Auth::user()->mailboxesCanView() as $sidebar_mailbox)
        <a href="{{ route('mailboxes.workflows', ['mailbox_id' => $sidebar_mailbox->id]) }}" @if ($mailbox && $mailbox->id == $sidebar_mailbox->id) aria-current="page" @endif>{{ $sidebar_mailbox->name }}</a>
    @endforeach
</x-page-nav>
