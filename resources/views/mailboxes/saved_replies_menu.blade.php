{{-- Saved Replies (a main sidebar item): a tab for each mailbox whose saved replies the user may manage. --}}
<x-page-nav :label="__('Saved Replies')">
    <x-slot:title><h1>{{ __('Saved Replies') }}</h1></x-slot:title>
    @foreach (Auth::user()->mailboxesCanView()->filter(fn ($menu_mailbox) => App\SavedReply::canManage(Auth::user(), $menu_mailbox)) as $menu_mailbox)
        <a wire:navigate href="{{ route('mailboxes.saved_replies', ['id' => $menu_mailbox->id]) }}" @if ($menu_mailbox->id == $mailbox->id) aria-current="page" @endif>{{ $menu_mailbox->name }}</a>
    @endforeach
</x-page-nav>
