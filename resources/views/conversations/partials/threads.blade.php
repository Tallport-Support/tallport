{{-- The threads, newest first; in the chat view ($chat) oldest first, under a divider for each day. --}}
@php
    $threads = collect($threads)->values();
@endphp
@foreach ($threads as $thread_index => $thread)
	@if (\Helper::isPrint() && app('request')->input('print_thread_id') && app('request')->input('print_thread_id') != $thread->id)
		@continue
	@endif
    @php
        $older_thread = empty($chat) ? ($threads[$thread_index + 1] ?? null) : ($threads[$thread_index - 1] ?? null);
    @endphp
    @if (!empty($chat) && (!$older_thread || App\Misc\Helper::userDate($older_thread->created_at) != App\Misc\Helper::userDate($thread->created_at)))
        <li class="conv-day" wire:key="day-{{ App\Misc\Helper::userDate($thread->created_at) }}"><x-fruit::divider>{{ App\Misc\Helper::dayName($thread->created_at) }}</x-fruit::divider></li>
    @endif
    @include('conversations/partials/thread')
@endforeach
