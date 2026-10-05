{{-- The threads, newest first. --}}
@php
    $threads = collect($threads)->values();
@endphp
@foreach ($threads as $thread_index => $thread)
	@if (\Helper::isPrint() && app('request')->input('print_thread_id') && app('request')->input('print_thread_id') != $thread->id)
		@continue
	@endif
    @php
        $older_thread = $threads[$thread_index + 1] ?? null;
    @endphp
    @include('conversations/partials/thread')
@endforeach
