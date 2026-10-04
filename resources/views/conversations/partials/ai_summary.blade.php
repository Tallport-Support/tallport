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
        <x-fruit::generated :label="__('Summary')" class="thread thread-type-ai-summary" id="thread-ai-summary" :title="!empty($ai_summary['at']) ? App\User::dateFormat($ai_summary['at']) : null">
            <ul class="ai-assistant-summary-list">
                @foreach ($ai_summary_items as $ai_item)
                    <li>{{ $ai_item }}</li>
                @endforeach
            </ul>
        </x-fruit::generated>
    </li>
@endif
