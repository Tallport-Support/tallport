{{-- AI Assistant: why a customer's message has no translation ($ai_untranslated: Translations::reason()), folded under "Not translated" (conversations/partials/thread). --}}
@if ($ai_untranslated[0] == 'same')
    {{ __('The AI took this message to be in :language already, though it detected :detected.', ['language' => App\Ai\Settings::displayName($ai_language), 'detected' => App\Ai\Settings::displayName($ai_untranslated[1])]) }}
@elseif ($ai_untranslated[0] == 'no_text')
    {{ __('The message has no text.') }}
@elseif ($ai_untranslated[0] == App\Ai\Translations::LIMIT_BUDGET)
    {{ __('This mailbox has used its AI tokens for today.') }}
@elseif ($ai_untranslated[0] == App\Ai\Translations::LIMIT_CUSTOMER)
    {{ __('This customer sent more messages in the last hour than are translated.') }}
@elseif ($ai_untranslated[0] == 'error')
    {{ __('The AI failed (:error). It tries again when the conversation is opened.', ['error' => $ai_untranslated[1]]) }}
@endif
