{{-- Settings › Mailboxes as a drill-down level (layouts/app), for admins: a row per mailbox leading to its
     settings, in the mailbox, with a menu (its settings, the mailbox, Delete Mailbox with the password).
     New Mailbox asks for its name and opens its General settings; its email and other channels are set
     up there. --}}
<x-fruit::drilldown-column :title="__('Mailboxes')" id="mailboxes">
    <x-slot:back><x-fruit::back-link :href="route('settings')" wire:navigate>{{ __('Settings') }}</x-fruit::back-link></x-slot:back>
    <x-slot:actions><x-fruit::button size="small" id="mailboxes-new" x-data x-on:click="$dispatch('fruit-dialog-open', { name: 'new-mailbox' })">{{ __('New Mailbox') }}</x-fruit::button></x-slot:actions>
    <x-fruit::page width="narrow">
        <x-fruit::form-section class="mailboxes-list" :aria-label="__('Mailboxes')" :footer="__('A mailbox’s address, signature, connection and people are in its own settings, in the mailbox.')">
            @foreach (App\Misc\MailboxSettings::listFor(Auth::user()) as $mailbox_item)
                <div class="f-form-row mailbox-row" data-mailbox_id="{{ $mailbox_item->id }}">
                    <a wire:navigate href="{{ route('mailboxes.update', ['id' => $mailbox_item->getSlug()]) }}" class="mailbox-row__main mailbox-row__link">
                        {{-- A module's image of the mailbox (mailbox_card.before_name) instead of its colour. --}}
                        @if (!Eventy::filter('mailbox.has_img', false, $mailbox_item))
                            <x-icon.mail class="f-icon mailbox-row__icon" :data-fruit-mark="$mailbox_item->accent ?: 'blue'" aria-hidden="true" />
                        @endif
                        <span class="mailbox-row__identity">
                            <span class="mailbox-row__name">@action('mailbox_card.before_name', $mailbox_item)<span class="mailbox-row__label">{{ $mailbox_item->name }}</span>@if ($mailbox_item->isArchived()) <x-fruit::badge>{{ __('Archived') }}</x-fruit::badge>@endif</span>
                            <span class="mailbox-row__address">{{ $mailbox_item->hasEmail() ? $mailbox_item->email : __('No email address') }}</span>
                        </span>
                    </a>
                    <x-fruit::menu :title="$mailbox_item->name">
                        <x-slot:trigger class="f-button--ghost f-button--icon f-button--small" :aria-label="__('More for :name', ['name' => $mailbox_item->name])" :title="__('More')"><x-icon.ellipsis class="f-icon" aria-hidden="true" /></x-slot:trigger>
                        <x-fruit::menu-link wire:navigate :href="route('mailboxes.update', ['id' => $mailbox_item->getSlug()])">{{ __('Mailbox Settings') }}</x-fruit::menu-link>
                        <x-fruit::menu-link wire:navigate :href="route('mailboxes.view', ['id' => $mailbox_item->getSlug()])">{{ __('Open Mailbox') }}</x-fruit::menu-link>
                        <x-fruit::menu-separator />
                        <x-fruit::menu-item variant="danger" x-on:click="$dispatch('fruit-dialog-open', { name: 'delete-mailbox-{{ $mailbox_item->id }}' })">{{ __('Delete Mailbox…') }}</x-fruit::menu-item>
                    </x-fruit::menu>
                </div>
            @endforeach
        </x-fruit::form-section>
    </x-fruit::page>

    @foreach (App\Misc\MailboxSettings::listFor(Auth::user()) as $mailbox_item)
        <x-fruit::dialog :name="'delete-mailbox-'.$mailbox_item->id" :aria-labelledby="'delete-mailbox-'.$mailbox_item->id.'-title'">
            <form x-data="tallportDeleteMailbox({{ $mailbox_item->id }})" x-on:submit.prevent="remove">
                <header class="f-dialog__header">
                    <h2 id="delete-mailbox-{{ $mailbox_item->id }}-title">{{ __('Delete the :mailbox_name mailbox?', ['mailbox_name' => $mailbox_item->name]) }}</h2>
                </header>
                <div class="f-dialog__body f-stack">
                    <p>{{ __('Deleting this mailbox will remove all historical data and deactivate related workflows and reports.') }}</p>
                    @if (!Auth::user()->isDummyPassword())
                        <x-fruit::field :label="__('Please confirm your password:')">
                            <x-fruit::input type="password" :id="'delete_mailbox_password_'.$mailbox_item->id" autocomplete="current-password" />
                        </x-fruit::field>
                    @endif
                </div>
                <footer class="f-dialog__footer">
                    <x-fruit::button type="button" x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</x-fruit::button>
                    <x-fruit::button type="submit" variant="danger">{{ __('Delete Mailbox') }}</x-fruit::button>
                </footer>
            </form>
        </x-fruit::dialog>
    @endforeach

    {{-- Opened again when what was entered needs correcting, and by the older New Mailbox address (?new). --}}
    <x-fruit::dialog name="new-mailbox" aria-labelledby="new-mailbox-title">
        <form method="POST" action="{{ route('mailboxes.create') }}" @if ($errors->any() || request()->has('new')) x-data x-init="$nextTick(() => $dispatch('fruit-dialog-open', { name: 'new-mailbox' }))" @endif>
            {{ csrf_field() }}
            <header class="f-dialog__header">
                <h2 id="new-mailbox-title">{{ __('New Mailbox') }}</h2>
            </header>
            <div class="f-dialog__body f-stack">
                <x-fruit::field :label="__('Mailbox Name')">
                    <x-fruit::input id="new_mailbox_name" name="name" :value="old('name')" maxlength="40" required autofocus />
                </x-fruit::field>
            </div>
            <footer class="f-dialog__footer">
                <x-fruit::button type="button" x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</x-fruit::button>
                <x-fruit::button type="submit" variant="primary">{{ __('Create') }}</x-fruit::button>
            </footer>
        </form>
    </x-fruit::dialog>
</x-fruit::drilldown-column>
