@if (!empty($mailbox->mute) || (!empty($mailbox->settings) && $mailbox->settings->mute))<x-icon.volume-off class="f-icon mailbox-mute-icon" aria-hidden="true" /> @endif
