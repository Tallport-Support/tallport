{{-- A mailbox's commands ($menu_mailbox), the same in the list's toolbar ("…") and its row's context menu in
     the sidebar: its settings, the user's own notifications for it ($menu_muted: muted or not, tallportMuteMailbox),
     and modules' commands (mailbox.sidebar.buttons). --}}
@if (Auth::user()->can('updateSettings', $menu_mailbox) || Auth::user()->can('updateEmailSignature', $menu_mailbox) || Auth::user()->can('update', $menu_mailbox))
    <x-fruit::menu-link wire:navigate :href="route('mailboxes.update', ['id' => $menu_mailbox->getSlug()])">{{ __('Mailbox Settings') }}</x-fruit::menu-link>
@endif
<x-fruit::menu-checkbox x-data="tallportMuteMailbox({{ $menu_mailbox->id }}, {{ $menu_muted ? 'true' : 'false' }})" x-on:click="toggle" x-bind:aria-checked="muted ? 'true' : 'false'">{{ __('Mute Notifications') }}</x-fruit::menu-checkbox>
<ul class="app-sidebar__module-items">@action('mailbox.sidebar.buttons', $menu_mailbox)</ul>
