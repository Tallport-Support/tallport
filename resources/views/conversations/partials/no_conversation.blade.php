{{-- Beside a folder's list, without a conversation open (App\Livewire\ConversationPane, and the
     browser's copy of it: livewire/conversation-list). --}}
<x-fruit::empty-state class="split-view__empty">
    <x-slot:icon><x-icon.mail-open /></x-slot:icon>
    <x-slot:title>{{ __('No conversation selected') }}</x-slot:title>
    {{ __('Choose a conversation from the list.') }}
</x-fruit::empty-state>
