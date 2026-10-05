{{-- AI Assistant: a message's translation (App\Ai\Translations), shown in the message's translation slot. --}}
@php
    if (!isset($ai_translation)) {
        ['language' => $ai_language, 'translation' => $ai_translation] = App\Ai\Translations::forThread($thread, Auth::user());
    }
@endphp
@if ($ai_translation)
    {{-- As the message looks (links, images, a business card), made safe like the message; older translations are text. --}}
    @if (App\Ai\Translations::isHtml($thread, $ai_language) && preg_match('/<[a-z][^>]*>/i', $ai_translation))
        <div class="ai-translation-html">{!! safe_raw_html($ai_translation) !!}</div>
    @else
        {!! nl2br(e($ai_translation)) !!}
    @endif
    @if (!empty(App\Ai\Summaries::data($thread)['truncated']))
        <br><small class="f-muted">{{ __('Only the first :count characters were translated.', ['count' => App\Ai\Summaries::MAX_THREAD_CHARS]) }}</small>
    @endif
@endif
