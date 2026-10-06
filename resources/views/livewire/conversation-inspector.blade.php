{{-- The open conversation's customer (App\Livewire\ConversationInspector). --}}
<div id="conv-layout-customer">
    @include('conversations/partials/customer_sidebar')
    @action('conversation.after_customer_sidebar', $conversation)
    {{-- What the AI Assistant used on this conversation (translations, summaries, drafts). --}}
    @if ($ai_tokens = App\Ai\Usage::forConversation($conversation))
        <p class="conv-ai-usage">{{ __('AI Assistant: :count tokens', ['count' => number_format($ai_tokens)]) }}</p>
    @endif
</div>
