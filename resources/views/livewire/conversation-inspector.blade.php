{{-- The open conversation's customer (App\Livewire\ConversationInspector). --}}
<div id="conv-layout-customer">
    @include('conversations/partials/customer_sidebar')
    @action('conversation.after_customer_sidebar', $conversation)
</div>
