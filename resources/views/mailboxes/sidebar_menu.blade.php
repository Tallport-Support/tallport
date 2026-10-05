{{-- A mailbox's settings: the mailbox (to switch) and a link to it. Its pages are in the app's sidebar (partials/app_sidebar_settings). --}}
@php
    $menu_mailboxes = auth()->user()->mailboxesCanView();
@endphp
<x-page-nav :label="__('Mailbox Settings')">
    <x-slot:title>
        @action('mailbox.update.before_mailbox_name', $mailbox)
        @if (count($menu_mailboxes) > 1)
            <x-fruit::menu :title="$mailbox->name" class="page-nav__switcher">
                @foreach ($menu_mailboxes as $mailbox_item)
                    <x-fruit::menu-link :href="route(Eventy::filter('mailboxes.menu_current_route', Route::currentRouteName()), ['id' => $mailbox_item->id])" :aria-current="$mailbox_item->id == $mailbox->id ? 'page' : null">@action('mailbox.update.dropdown.before_mailbox_name', $mailbox_item){{ $mailbox_item->name }}</x-fruit::menu-link>
                @endforeach
            </x-fruit::menu>
        @else
            <h1>{{ $mailbox->name }}</h1>
        @endif
        <small class="f-muted">{{ $mailbox->email }}</small>
    </x-slot:title>
    <x-slot:actions>
        <a wire:navigate href="{{ route('mailboxes.view', ['id' => $mailbox->id]) }}" class="f-button">{{ __('Open Mailbox') }}</a>
    </x-slot:actions>
</x-page-nav>
