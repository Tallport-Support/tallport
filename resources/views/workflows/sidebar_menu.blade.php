{{-- Workflows (a main sidebar item): of all mailboxes (admins), with a tab for each mailbox the user may
     edit them in; a mailbox's are a page of its settings, in the mailbox (mailboxes/sidebar_menu). --}}
@if (!empty($mailbox))
    @include('mailboxes/sidebar_menu')
@else
<x-page-nav :label="__('Workflows')">
    <x-slot:title><h1>{{ __('Workflows') }}</h1></x-slot:title>
    @if (Auth::user()->isAdmin())
        <a wire:navigate href="{{ route('workflows') }}" @if (!$mailbox) aria-current="page" @endif>{{ __('All Mailboxes') }}</a>
    @endif
    @foreach (Auth::user()->mailboxesCanView()->filter(fn ($sidebar_mailbox) => App\Workflow::canEdit(Auth::user(), $sidebar_mailbox)) as $sidebar_mailbox)
        <a wire:navigate href="{{ route('mailboxes.workflows', ['mailbox_id' => $sidebar_mailbox->getSlug()]) }}" @if ($mailbox && $mailbox->id == $sidebar_mailbox->id) aria-current="page" @endif>{{ $sidebar_mailbox->name }}</a>
    @endforeach
</x-page-nav>
@endif
