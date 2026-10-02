{{-- AI Assistant: a customer's message translated (App\Ai\Translations), or a reply made from a draft with the draft's translation. --}}
@if (($thread->type == App\Thread::TYPE_CUSTOMER && App\Ai\Translations::isWanted($thread)) || ($thread->type == App\Thread::TYPE_MESSAGE && $thread->ai_assistant))
    @php
        $ai_language = App\Ai\Settings::language($conversation->mailbox, Auth::user());
        $ai_translation = App\Ai\Translations::get($thread, $ai_language);
        if ($thread->type == App\Thread::TYPE_CUSTOMER && App\Ai\Translations::isMissing($thread, $ai_language)) {
            App\Jobs\AiTranslateThread::request($thread, $ai_language);
        }
    @endphp
    @if ($ai_translation)
        <div class="margin-bottom">
            <div class="alert alert-ai-translation">
                <div class="alert-ai-translation-title">{{ __('AI Translation') }}</div>
                {!! nl2br(e($ai_translation)) !!}
            </div>
        </div>
    @endif
@endif
