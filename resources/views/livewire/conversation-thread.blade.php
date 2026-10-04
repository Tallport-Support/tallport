{{-- The conversation's history (App\Livewire\ConversationThread). --}}
<x-fruit::thread id="conv-layout-main" :aria-label="__('Conversation History')">
    @include('conversations/partials/ai_summary')
    @include('conversations/partials/threads')
</x-fruit::thread>
