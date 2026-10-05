<section class="conv-sidebar-block inspector-section">
    <h3>{{ __('Conversations') }}</h3>
    <ul class="sidebar-block-list">
        @foreach ($prev_conversations as $prev_conversation)
            <li>
                <a href="{{ $prev_conversation->url() }}" target="_blank">@if ($prev_conversation->isPhone())<x-icon.phone class="f-icon" aria-hidden="true" />@else<x-icon.mail class="f-icon" aria-hidden="true" />@endif<span>{{ $prev_conversation->getSubject() }}</span></a>
            </li>
        @endforeach
    </ul>
    @if ($prev_conversations->hasMorePages())
        <a href="{{ route('customers.conversations', ['id' => $customer->id])}}" class="sidebar-block-link">{{ __("View all :number", ['number' => $prev_conversations->total()]) }}</a>
    @endif
</section>
