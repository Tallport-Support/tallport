{{-- The conversation's history (App\Livewire\ConversationThread); in the chat view, in a
     history that opens at the newest message and follows new ones (FruitUI). --}}
@if ($chat)
    <x-fruit::history :aria-label="__('Conversation History')" class="conv-thread conv-history" wire:key="chat-{{ $conversation->id }}">
        <x-fruit::thread id="conv-layout-main" :aria-label="__('Conversation History')">
            @include('conversations/partials/ai_summary')
            @include('conversations/partials/threads', ['chat' => true])
        </x-fruit::thread>
    </x-fruit::history>
@else
    <x-fruit::thread id="conv-layout-main" :aria-label="__('Conversation History')">
        @include('conversations/partials/ai_summary')
        @include('conversations/partials/threads')
    </x-fruit::thread>
@endif
