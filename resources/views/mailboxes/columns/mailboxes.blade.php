{{-- Settings › Mailboxes as a drill-down level (layouts/app): a row per mailbox, the open one ($mailbox)
     current: the page, or on the way to a page of its ($mailbox_section). --}}
<x-fruit::drilldown-column :title="__('Mailboxes')" id="mailboxes">
    <x-slot:back><x-fruit::back-link :href="route('settings')" wire:navigate>{{ __('Settings') }}</x-fruit::back-link></x-slot:back>
    @if (Auth::user()->isAdmin())
        <x-slot:actions><a class="f-button f-button--small" href="{{ route('mailboxes.create') }}" wire:navigate>{{ __('New Mailbox') }}</a></x-slot:actions>
    @endif
    <x-fruit::page width="narrow">
        {{-- Each mailbox: its colour, name and address, and its people (or what needs setting up); its page next. --}}
        <x-fruit::form-section class="mailboxes-list" :aria-label="__('Mailboxes')">
            @foreach (App\Misc\MailboxSettings::listFor(Auth::user()) as $mailbox_item)
                @php
                    [$mailbox_connection, $mailbox_problem] = $mailbox_item->isConnected() ? [null, false] : App\Misc\MailboxSettings::connection($mailbox_item);
                @endphp
                <a wire:navigate href="{{ App\Misc\MailboxSettings::url($mailbox_item, Auth::user()) }}" class="f-form-row f-form-row--link mailbox-row" @if (isset($mailbox) && $mailbox->id == $mailbox_item->id) aria-current="{{ empty($mailbox_section) ? 'page' : 'true' }}" @endif>
                    <span class="mailbox-row__main">
                        {{-- A module's image of the mailbox (mailbox_card.before_name) instead of its colour. --}}
                        @if (!Eventy::filter('mailbox.has_img', false, $mailbox_item))
                            <x-icon.mail class="f-icon mailbox-row__icon" :data-fruit-mark="$mailbox_item->accent ?: 'blue'" aria-hidden="true" />
                        @endif
                        <span class="mailbox-row__identity">
                            <span class="mailbox-row__name">@action('mailbox_card.before_name', $mailbox_item){{ $mailbox_item->name }}@if ($mailbox_item->isArchived()) <x-fruit::badge>{{ __('Archived') }}</x-fruit::badge>@endif</span>
                            <span class="mailbox-row__address">{{ $mailbox_item->email }}</span>
                        </span>
                    </span>
                    <span class="f-form-row__value @if ($mailbox_problem) mailbox-pages__problem @endif">{{ $mailbox_problem ? $mailbox_connection : trans_choice(':count person|:count people', count($mailbox_item->usersHavingAccess(true))) }}</span>
                </a>
            @endforeach
        </x-fruit::form-section>
    </x-fruit::page>
</x-fruit::drilldown-column>
