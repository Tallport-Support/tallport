{{-- A mailbox's settings pages' header (App\Misc\MailboxSettings). Its own page: Back to the Mailboxes,
     the mailbox (to switch), its address and a link to it. A further page: Back to the mailbox, and its name. --}}
@php
    $menu_route = Route::currentRouteName();
    $menu_page_title = $menu_route == 'mailboxes.update' ? null : App\Misc\MailboxSettings::currentTitle($mailbox, Auth::user(), $menu_route);
    $menu_mailboxes = auth()->user()->mailboxesCanView();
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
            @if (count($menu_mailboxes) > 1)
                <x-fruit::menu :title="$mailbox->name" class="page-nav__switcher">
                    @foreach ($menu_mailboxes as $mailbox_item)
                        <x-fruit::menu-link :href="route(Eventy::filter('mailboxes.menu_current_route', $menu_route), ['id' => $mailbox_item->id])" :aria-current="$mailbox_item->id == $mailbox->id ? 'page' : null">@action('mailbox.update.dropdown.before_mailbox_name', $mailbox_item){{ $mailbox_item->name }}</x-fruit::menu-link>
                    @endforeach
                </x-fruit::menu>
            @else
                <h1>{{ $mailbox->name }}</h1>
            @endif
            <small class="f-muted">{{ $mailbox->email }}</small>
        @endif
    </x-slot:title>
    @if ($menu_page_title === null)
        <x-slot:actions>
            <a wire:navigate href="{{ route('mailboxes.view', ['id' => $mailbox->id]) }}" class="f-button">{{ __('Open Mailbox') }}</a>
        </x-slot:actions>
    @endif
</x-page-nav>
