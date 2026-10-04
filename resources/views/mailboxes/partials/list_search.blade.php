{{-- Search at the top of the list pane, as in FruitUI's Support example: the mailbox's conversations. --}}
<form class="conv-list-search" role="search" action="{{ route('conversations.search') }}">
    @if (!empty($mailbox->id) && $mailbox->id > 0)
        <input type="hidden" name="f[mailbox]" value="{{ $mailbox->id }}">
    @endif
    <x-fruit::search name="q" :label="__('Search')" :placeholder="__('Search conversations')" id="search-dt" />
</form>
