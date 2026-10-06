{{-- The open conversation's column (App\Livewire\ConversationPane): the email view, or the
     chat view (the user's choice for its channel). Another conversation opens in it in place. --}}
<div class="conv-pane">
    @if ($chat_view)
        {{-- Chat view: a one-line heading, the history (oldest first, opening at the newest
             message) and the composer docked below it (FruitUI's history and composer). --}}
        <div id="conv-layout" class="conv-chat conv-type-{{ strtolower($conversation->getTypeName()) }}">
            <header class="conv-heading conv-heading--chat">
                <livewire:conversation-subject wire:key="subject-{{ $conversation->id }}" :conversation="$conversation" :viewers="$viewers" compact />
                {{-- Led by the person, as in a messaging app: their picture, name, and the channel. --}}
                @if ($customer)
                    @include('customers/partials/avatar', ['class' => 'conv-heading__avatar'])
                @endif
                <p class="conv-heading__customer">@if ($customer)<strong>{{ $customer->getFullName(true) }}</strong>@endif</p>
                @if ($conversation->hasChannel() && $conversation->getChannelName())
                    <p class="conv-heading__mailbox"><x-icon.message-circle class="f-icon" aria-hidden="true" /><span>{{ $conversation->getChannelName() }} · {{ $mailbox->name }}</span></p>
                @else
                    <p class="conv-heading__mailbox"><x-icon.mail class="f-icon" aria-hidden="true" /><span>{{ $mailbox->name }}@if ($mailbox->email) · {{ $mailbox->email }}@endif</span></p>
                @endif
                {{-- Where the customer writes from (their latest message), rather than on every message. --}}
                @php $chat_last_customer = collect($threads)->first(fn ($chat_thread) => $chat_thread->isCustomerMessage()); @endphp
                @if ($chat_last_customer && App\Nostr\Nostr::isNostr($conversation))
                    <div class="conv-heading__device">@include('nostr/partials/thread_sender', ['sender' => App\Nostr\NostrEvent::sender($conversation, $threads, $chat_last_customer), 'thread' => $chat_last_customer])</div>
                @endif
                @action('conversation.after_subject', $conversation, $mailbox)
            </header>
            @action('conversation.after_subject_block', $conversation, $mailbox)
            @action('conversation.before_threads', $conversation)
            <livewire:conversation-thread wire:key="thread-{{ $conversation->id }}" :conversation="$conversation" :threads="$threads->reverse()->values()" chat />
            @action('conversation.after_threads', $conversation)
            <livewire:conversation-composer wire:key="composer-{{ $conversation->id }}" :conversation="$conversation" :to-customers="$to_customers" :cc="$cc" :from-aliases="$from_aliases" :from-alias="$from_alias" :after-send="$after_send" chat />
        </div>
    @else
        <div id="conv-layout" class="conv-type-{{ strtolower($conversation->getTypeName()) }}">
            <div id="conv-layout-header">
                <div id="conv-subject">
                    <header class="conv-heading">
                        <livewire:conversation-subject wire:key="subject-{{ $conversation->id }}" :conversation="$conversation" :viewers="$viewers" />
                        @if ($customer)
                            <p>{{ $customer->getFullName(true) }}@if ($conversation->customer_email && $conversation->customer_email != $customer->getFullName(true)) · {{ $conversation->customer_email }}@endif</p>
                        @endif
                        {{-- How the conversation reaches the mailbox: its address, or a chat channel (Nostr, Telegram). --}}
                        @if ($conversation->hasChannel() && $conversation->getChannelName())
                            <p class="conv-heading__mailbox"><x-icon.message-circle class="f-icon" aria-hidden="true" /><span>{{ $mailbox->name }} · {{ $conversation->getChannelName() }}</span></p>
                        @else
                            <p class="conv-heading__mailbox"><x-icon.mail class="f-icon" aria-hidden="true" /><span>{{ $mailbox->name }}@if ($mailbox->email) · {{ $mailbox->email }}@endif</span></p>
                        @endif
                        @action('conversation.after_subject', $conversation, $mailbox)
                    </header>
                    @action('conversation.after_subject_block', $conversation, $mailbox)
                    <livewire:conversation-composer wire:key="composer-{{ $conversation->id }}" :conversation="$conversation" :to-customers="$to_customers" :cc="$cc" :from-aliases="$from_aliases" :from-alias="$from_alias" :after-send="$after_send" />
                </div>
            </div>

            <div class="conv-thread">
                @action('conversation.before_threads', $conversation)
                <livewire:conversation-thread wire:key="thread-{{ $conversation->id }}" :conversation="$conversation" :threads="$threads" />
                @action('conversation.after_threads', $conversation)
            </div>
        </div>
    @endif
</div>
