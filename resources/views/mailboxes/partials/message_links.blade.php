{{-- A channel's automatic messages ($key, App\AutoReply\ChannelMessages), a group at the end of its page: a row per
     message, linking to the channel's group on the Greeting or Auto Reply page, with its choice (the mailbox's, its own
     or none). For those who may change them. --}}
@can('updateAutoReply', $mailbox)
    <x-fruit::form-section :title="__('Automatic Messages')" :level="3" :footer="__('Written once for every channel, under Automatic Messages.')">
        @if (App\AutoReply\ChannelMessages::channels()[$key]['greets'])
            <a class="f-form-row f-form-row--link" href="{{ route('mailboxes.greeting', ['id' => $mailbox->getSlug()]) }}#greeting-{{ $key }}" wire:navigate><span>{{ __('Greeting') }}</span><span class="f-form-row__value">{{ App\AutoReply\ChannelMessages::modeName($mailbox, $key, App\AutoReply\ChannelMessages::GREETING) }}</span></a>
        @endif
        <a class="f-form-row f-form-row--link" href="{{ route('mailboxes.auto_reply', ['id' => $mailbox->getSlug()]) }}#auto-reply-{{ $key }}" wire:navigate><span>{{ __('Auto Reply') }}</span><span class="f-form-row__value">{{ App\AutoReply\ChannelMessages::modeName($mailbox, $key, App\AutoReply\ChannelMessages::AUTO_REPLY) }}</span></a>
    </x-fruit::form-section>
@endcan
