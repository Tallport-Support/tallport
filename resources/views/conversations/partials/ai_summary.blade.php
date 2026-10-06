{{-- AI Assistant: where the conversation stands (App\Ai\Summaries), a slim line above the messages; for long
     conversations, what's been tried and what's open behind Background. --}}
@php
    $ai_language = App\Ai\Settings::language($conversation->mailbox, Auth::user());
    $ai_summary = App\Ai\Summaries::get($conversation, $ai_language) ?: App\Ai\Summaries::getAny($conversation, $ai_language);
    if (App\Ai\Summaries::isWanted($conversation) && App\Ai\Summaries::isStale($conversation, $ai_language)) {
        App\Jobs\AiSummarizeConversation::request($conversation, $ai_language);
    }
    $ai_one_liner = trim($ai_summary['one_liner'] ?? '');
    $ai_background = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($ai_summary['background'] ?? '')) as $ai_line) {
        $ai_line = trim(preg_replace('/^[-*]\s+/', '', trim($ai_line)));
        if ($ai_line !== '') {
            $ai_background[] = $ai_line;
        }
    }
    $ai_summary_title = !empty($ai_summary['at']) ? App\User::dateFormat($ai_summary['at']) : null;
@endphp
@if ($ai_one_liner !== '')
    <li>
        <div class="thread thread-type-ai-summary ai-summary-line" id="thread-ai-summary" title="{{ $ai_summary_title }}">
            <x-icon.sparkles class="f-icon ai-assistant-icon" aria-hidden="true" /><span class="f-sr-only">{{ __('Summary') }}: </span><span>{{ $ai_one_liner }}</span>
            @if ($ai_background)
                <details class="ai-summary-line__background">
                    <summary>{{ __('Background') }}</summary>
                    <ul class="ai-assistant-summary-list">@foreach ($ai_background as $ai_item)<li>{{ $ai_item }}</li>@endforeach</ul>
                </details>
            @endif
        </div>
    </li>
@endif
