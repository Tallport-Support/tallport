{{-- The conversation's history (App\Livewire\ConversationThread); in the chat view, in a
     history that opens at the newest message and follows new ones (FruitUI), oldest first,
     a divider and a list for each day. --}}
@if ($chat)
    @php
        $threads = collect($threads)->values();
        $thread_positions = $threads->pluck('id')->flip();
        $ai_summary_html = trim(view('conversations/partials/ai_summary', get_defined_vars())->render());
    @endphp
    <x-fruit::history :aria-label="__('Conversation History')" class="conv-thread conv-history" wire:key="chat-{{ $conversation->id }}">
        @if ($ai_summary_html !== '')
            <x-fruit::thread id="conv-layout-main" :aria-label="__('Summary')">{!! $ai_summary_html !!}</x-fruit::thread>
        @endif
        @foreach ($threads->groupBy(fn ($thread) => App\Misc\Helper::userDate($thread->created_at)) as $day => $day_threads)
            @php $day_name = App\Misc\Helper::dayName($day_threads->first()->created_at); @endphp
            <x-fruit::divider wire:key="divider-{{ $day }}">{{ $day_name }}</x-fruit::divider>
            <x-fruit::thread density="compact" wire:key="day-{{ $day }}" :aria-label="$day_name">
                @foreach ($day_threads as $thread)
                    @php $older_thread = $threads[$thread_positions[$thread->id] - 1] ?? null; @endphp
                    @include('conversations/partials/thread')
                @endforeach
            </x-fruit::thread>
        @endforeach
    </x-fruit::history>
@else
    <x-fruit::thread id="conv-layout-main" :aria-label="__('Conversation History')">
        @include('conversations/partials/ai_summary')
        @include('conversations/partials/threads')
    </x-fruit::thread>
@endif
