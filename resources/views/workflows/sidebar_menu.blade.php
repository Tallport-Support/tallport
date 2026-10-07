{{-- Workflows (a main sidebar item): of all mailboxes (admins) and of each mailbox the user may edit them in. --}}
<x-page-nav :label="__('Workflows')">
    {{-- Opened from the mailbox's settings (its row): Back to them, so the drill-down holds. --}}
    @if (request('from') == 'mailbox' && !empty($mailbox) && App\Misc\MailboxSettings::canOpenMailbox($mailbox, Auth::user()))
        <x-slot:back><x-fruit::back-link wire:navigate :href="route('mailboxes.update', ['id' => $mailbox->id])">{{ $mailbox->name }}</x-fruit::back-link></x-slot:back>
    @endif
    <x-slot:title><h1>{{ __('Workflows') }}</h1></x-slot:title>
    @if (Auth::user()->isAdmin())
        <a wire:navigate href="{{ route('workflows') }}" @if (!$mailbox) aria-current="page" @endif>{{ __('All Mailboxes') }}</a>
    @endif
    @foreach (Auth::user()->mailboxesCanView()->filter(fn ($sidebar_mailbox) => App\Workflow::canEdit(Auth::user(), $sidebar_mailbox)) as $sidebar_mailbox)
        <a wire:navigate href="{{ route('mailboxes.workflows', ['mailbox_id' => $sidebar_mailbox->id]) }}" @if ($mailbox && $mailbox->id == $sidebar_mailbox->id) aria-current="page" @endif>{{ $sidebar_mailbox->name }}</a>
    @endforeach
</x-page-nav>
