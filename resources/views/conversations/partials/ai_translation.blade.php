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
        {{-- Generated, not the customer's words: FruitUI's generated message. --}}
        <x-fruit::message layout="stacked" variant="generated" class="ai-translation">
            <x-slot:author>{{ __('AI Translation') }}</x-slot:author>
            <x-slot:meta>{{ __('Generated') }}</x-slot:meta>
            <p>{!! nl2br(e($ai_translation)) !!}</p>
            @if (!empty(App\Ai\Summaries::data($thread)['truncated']))
                <p class="f-footnote f-muted">{{ __('Only the first :count characters were translated.', ['count' => App\Ai\Summaries::MAX_THREAD_CHARS]) }}</p>
            @endif
        </x-fruit::message>
    @elseif ($thread->type == App\Thread::TYPE_CUSTOMER && ($ai_reason = App\Ai\Translations::reason($thread, $ai_language)))
        {{-- Why there is no translation. --}}
        <p class="f-footnote f-muted ai-translation-note"><x-heroicon-o-language class="f-icon" aria-hidden="true" />
            @if ($ai_reason[0] == 'same')
                {{ __('Not translated: the AI Assistant took this message to be in :language already, though it detected :detected.', ['language' => App\Ai\Settings::displayName($ai_language), 'detected' => App\Ai\Settings::displayName($ai_reason[1])]) }}
            @elseif ($ai_reason[0] == 'no_text')
                {{ __('Not translated: the message has no text.') }}
            @elseif ($ai_reason[0] == 'error')
                {{ __('Not translated: the AI Assistant failed (:error). It tries again when the conversation is opened.', ['error' => $ai_reason[1]]) }}
            @else
                {{ __('Waiting for the AI Assistant to translate this message.') }}
            @endif
        </p>
    @endif
@endif
