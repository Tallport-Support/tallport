{{-- A mailbox's settings pages' header (App\Misc\MailboxSettings), in Settings' bar. Its own page: Back to
     the Mailboxes, its name and a link to it. A further page: Back to the mailbox, and the page's name. --}}
@php
    $menu_route = Route::currentRouteName();
    $menu_page_title = $menu_route == 'mailboxes.update' ? null : App\Misc\MailboxSettings::currentTitle($mailbox, Auth::user(), $menu_route);
@endphp
<x-page-nav :label="__('Mailbox Settings')">
    <x-slot:back>
        @if ($menu_page_title === null)
            <x-fruit::back-link wire:navigate :href="route('mailboxes')">{{ __('Mailboxes') }}</x-fruit::back-link>
        @else
            {{-- Up a level: the mailbox, or the Mailboxes for someone who can't open its page. --}}
            @if (App\Misc\MailboxSettings::canOpenMailbox($mailbox, Auth::user()))
                <x-fruit::back-link wire:navigate :href="route('mailboxes.update', ['id' => $mailbox->id])">{{ $mailbox->name }}</x-fruit::back-link>
            @else
                <x-fruit::back-link wire:navigate :href="route('mailboxes')">{{ __('Mailboxes') }}</x-fruit::back-link>
            @endif
        @endif
    </x-slot:back>
    <x-slot:title>
        @if ($menu_page_title !== null)
            <h1>{{ $menu_page_title }}</h1>
        @else
            @action('mailbox.update.before_mailbox_name', $mailbox)
            <h1>{{ $mailbox->name }}</h1>
        @endif
    </x-slot:title>
    @if ($menu_page_title === null)
        <x-slot:actions>
            {{-- On a phone just its icon (named, with a tooltip). --}}
            <a wire:navigate href="{{ route('mailboxes.view', ['id' => $mailbox->id]) }}" class="f-button settings-toolbar__open" title="{{ __('Open Mailbox') }}" aria-label="{{ __('Open Mailbox') }}"><x-icon.inbox class="f-icon" aria-hidden="true" /><span>{{ __('Open Mailbox') }}</span></a>
        </x-slot:actions>
    @endif
</x-page-nav>
