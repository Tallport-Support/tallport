{{-- AI Assistant: the conversation's summary (App\Ai\Summaries). --}}
@php
    $ai_language = App\Ai\Settings::language($conversation->mailbox, Auth::user());
    $ai_summary = App\Ai\Summaries::get($conversation, $ai_language) ?: App\Ai\Summaries::getAny($conversation, $ai_language);
    if (App\Ai\Summaries::isWanted($conversation) && App\Ai\Summaries::isStale($conversation, $ai_language)) {
        App\Jobs\AiSummarizeConversation::request($conversation, $ai_language);
    }
    $ai_summary_items = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($ai_summary['summary'] ?? '')) as $ai_line) {
        $ai_line = trim(preg_replace('/^[-*]\s+/', '', trim($ai_line)));
        if ($ai_line !== '') {
            $ai_summary_items[] = $ai_line;
        }
    }
@endphp
@if ($ai_summary_items)
    <li>
    <x-fruit::message layout="stacked" class="thread thread-type-ai-summary" id="thread-ai-summary" :datetime="!empty($ai_summary['at']) ? \Illuminate\Support\Carbon::parse($ai_summary['at'])->toIso8601String() : null">
        <x-slot:avatar><x-fruit::avatar :src="asset('img/ai-assistant.png')" /></x-slot:avatar>
        <x-slot:author>{{ __('Summary') }}</x-slot:author>
        <x-slot:meta><x-fruit::badge tone="accent">AI</x-fruit::badge></x-slot:meta>
        @if (!empty($ai_summary['at']))
            <x-slot:time title="{{ App\User::dateFormat($ai_summary['at']) }}">{{ App\User::dateDiffForHumans($ai_summary['at']) }}</x-slot:time>
        @endif
        <ul class="ai-assistant-summary-list">
            @foreach ($ai_summary_items as $ai_item)
                <li>{{ $ai_item }}</li>
            @endforeach
        </ul>
    </x-fruit::message>
    </li>
@endif
