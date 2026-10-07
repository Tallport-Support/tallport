{{-- AI Assistant: why a customer's message has no translation (conversations/partials/thread). --}}
@if ($ai_translation_wanted)
    {{-- Waiting: in the translation's place instead (conversations/partials/thread). --}}
    @if (!$ai_translation && $thread->type == App\Thread::TYPE_CUSTOMER && ($ai_reason = App\Ai\Translations::reason($thread, $ai_language)) && $ai_reason[0] != 'waiting')
        {{-- Why there is no translation. --}}
        <p class="f-footnote f-muted ai-translation-note"><x-icon.languages class="f-icon" aria-hidden="true" />
            @if ($ai_reason[0] == 'same')
                {{ __('Not translated: the AI took this message to be in :language already, though it detected :detected.', ['language' => App\Ai\Settings::displayName($ai_language), 'detected' => App\Ai\Settings::displayName($ai_reason[1])]) }}
            @elseif ($ai_reason[0] == 'no_text')
                {{ __('Not translated: the message has no text.') }}
            @elseif ($ai_reason[0] == App\Ai\Translations::LIMIT_BUDGET)
                {{ __('Not translated: this mailbox has used its AI tokens for today.') }}
            @elseif ($ai_reason[0] == App\Ai\Translations::LIMIT_CUSTOMER)
                {{ __('Not translated: this customer sent more messages in the last hour than are translated.') }}
            @elseif ($ai_reason[0] == 'error')
                {{ __('Not translated: the AI failed (:error). It tries again when the conversation is opened.', ['error' => $ai_reason[1]]) }}
            @endif
        </p>
    @endif
@endif
