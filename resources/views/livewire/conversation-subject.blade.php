{{-- The heading's status, mailbox, star and subject, then who wrote in, the AI summary and who else has it open (App\Livewire\ConversationSubject). --}}
<div class="conv-heading__top @if ($is_following) conv-following @endif">
    <div class="conv-heading__overline">
        @if ($compact)
            <span>{{ $conversation->getStatusName() }} · #{{ $conversation->number }}</span>
        @else
            {{-- How the conversation reaches the mailbox beside it: its address, or a chat channel (Nostr, Telegram). --}}
            <span class="conv-heading__overline-start">
                <span>{{ $conversation->getStatusName() }} · #{{ $conversation->number }}</span>
                @if ($conversation->hasChannel() && $conversation->getChannelName())
                    <span class="conv-heading__mailbox"><x-icon.message-circle class="f-icon" aria-hidden="true" /><span>{{ $mailbox->name }} · {{ $conversation->getChannelName() }}</span></span>
                @else
                    <span class="conv-heading__mailbox"><x-icon.mail class="f-icon" aria-hidden="true" /><span>{{ $mailbox->name }}@if ($mailbox->email) · {{ $mailbox->email }}@endif</span></span>
                @endif
            </span>
        @endif
        <span class="conv-heading__tools">
            <button type="button" class="f-button f-button--ghost f-button--icon f-button--small conv-star" wire:click="star" aria-pressed="{{ $starred ? 'true' : 'false' }}" aria-label="{{ __('Star Conversation') }}" title="@if ($starred){{ __("Unstar Conversation") }}@else{{ __("Star Conversation") }}@endif"><x-icon.star class="f-icon conv-star__off" aria-hidden="true" /><x-icon.star fill="currentColor" class="f-icon conv-star__on" aria-hidden="true" /></button>
        </span>
    </div>
    @unless ($compact)
        <div class="conv-subjtext" x-data="{ editing: false, subject: @js($conversation->getSubject()) }" :class="{ 'conv-subj-editing': editing }">
            <h2 x-on:click="editing = true; $nextTick(() => $refs.subject.focus())">{{ $conversation->getSubject() }}</h2>
            <div class="f-input-group conv-subj-editor">
                <input type="text" id="conv-subj-value" class="f-input" x-ref="subject" x-model="subject" x-on:keydown.enter.prevent="$refs.save.click()" x-on:keydown.escape.prevent="editing = false" aria-label="{{ __('Subject') }}" />
                <button class="f-button f-button--primary" type="button" x-ref="save" x-on:click="Tallport.busy($el, true); $wire.saveSubject(subject).then(() => { Tallport.busy($el, false); editing = false })" aria-label="{{ __('Save') }}"><x-icon.check class="f-icon" aria-hidden="true" /></button>
            </div>
        </div>
        @if ($customer)
            {{-- Who wrote in: only while the inspector (which names them) is out of sight. --}}
            <p class="conv-heading__customer">{{ $customer->getFullName(true) }}@if ($conversation->customer_email && $conversation->customer_email != $customer->getFullName(true)) · {{ $conversation->customer_email }}@endif</p>
        @endif
        @include('conversations/partials/ai_summary')
        @include('conversations/partials/presence_line')
    @endunless
</div>
