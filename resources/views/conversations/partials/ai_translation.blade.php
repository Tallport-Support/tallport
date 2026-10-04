{{-- AI Assistant: a message's translation (App\Ai\Translations), shown in the message's translation slot. --}}
@php
    if (!isset($ai_translation)) {
        ['language' => $ai_language, 'translation' => $ai_translation] = App\Ai\Translations::forThread($thread, Auth::user());
    }
@endphp
@if ($ai_translation)
    {!! nl2br(e($ai_translation)) !!}
    @if (!empty(App\Ai\Summaries::data($thread)['truncated']))
        <br><small class="f-muted">{{ __('Only the first :count characters were translated.', ['count' => App\Ai\Summaries::MAX_THREAD_CHARS]) }}</small>
    @endif
@endif
