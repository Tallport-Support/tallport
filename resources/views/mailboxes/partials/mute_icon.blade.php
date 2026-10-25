{{-- A mailbox whose notifications the user muted: a bell-slash, shown or hidden as they mute it (tallportMuteMailbox). --}}
@if ($mailbox->id > 0)<x-icon.bell-off class="f-icon mailbox-mute-icon" aria-hidden="true" :data-muted-mailbox="$mailbox->id" :hidden="empty($mailbox->mute) && (empty($mailbox->settings) || !$mailbox->settings->mute)" /> @endif
