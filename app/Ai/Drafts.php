<?php

namespace App\Ai;

use App\Ai\Agents\ReplyDrafter;
use App\Conversation;
use App\Thread;

/**
 * Reply drafts in the customer's language, using the shared conversation context.
 */
class Drafts
{
    /**
     * The newest messages a draft is made from.
     */
    const MAX_THREADS = ConversationContext::MAX_MESSAGES;

    const MAX_THREAD_CHARS = ConversationContext::MAX_MESSAGE_CHARS;

    const MAX_DOCUMENT_CHARS = ConversationContext::MAX_DOCUMENT_CHARS;

    /**
     * Whether a user can draft replies in a conversation (now).
     */
    public static function allowed($user, Conversation $conversation)
    {
        return $user
            && Settings::isConfigured()
            && Settings::enabled('drafts', $conversation->mailbox)
            && Settings::draftsPerDay($user) > 0
            && $user->can('view', $conversation);
    }

    public static function limitReached($user)
    {
        return DraftJob::countToday($user) >= Settings::draftsPerDay($user);
    }

    /**
     * Make a draft. $language: the language the user reads its translation in. $on_draft: the
     * draft so far (Markdown), as it's written, and its translation so far.
     */
    public static function draft(Conversation $conversation, $language, ?callable $on_draft = null, ?float $deadline = null)
    {
        $deadline = $deadline ?? microtime(true) + 120;
        $context = ConversationContext::build($conversation, $language, false, null, $deadline);
        [$answer] = (new ReplyDrafter($language))->recordFor(Usage::FEATURE_DRAFT, $conversation)->streamJson(
            $context['prompt'], $on_draft ? fn ($answer) => $on_draft((string) ($answer['draft'] ?? ''), (string) ($answer['translation'] ?? '')) : null, $deadline
        );

        return [
            'draft'                   => trim((string) ($answer['draft'] ?? '')),
            'translation'             => trim((string) ($answer['translation'] ?? '')),
            'translation_language'    => $language,
            'language'                => (string) ($answer['language'] ?? ''),
            'confidence'              => in_array($answer['confidence'] ?? null, ['low', 'medium', 'high']) ? $answer['confidence'] : 'low',
            'documentation_urls'      => array_values(array_filter(array_filter((array) ($answer['documentation_urls'] ?? []), 'is_string'), [Document::class, 'isHttpUrl'])),
            'staff_notes'             => array_values(array_map('strval', array_filter((array) ($answer['staff_notes'] ?? []), 'is_scalar'))),
            'retrieved_documents'     => $context['documentation']['chunks'],
            'documentation_status'    => $context['documentation']['status'],
            'customer_context_status' => $context['customer_context']['status'],
        ];
    }

    /**
     * A reply made from a draft keeps the draft's translation, shown with it.
     */
    public static function keepTranslation(Thread $thread, $request)
    {
        $translation = trim(strip_tags((string) $request->input('ai_draft_translation', '')));
        $language = (string) $request->input('ai_draft_translation_language', '');
        if ($thread->type != Thread::TYPE_MESSAGE || $translation === '' || !Settings::isLanguage($language)) {
            return;
        }
        $data = Summaries::data($thread);
        $data['translations'][$language] = mb_substr($translation, 0, 20000);
        // The agent's own version (written, or read in the draft): shown as the message, the
        // version sent beside it (conversations/partials/thread).
        $data['written_in'] = $language;
        $data['sent_in'] = $data['sent_in'] ?? ChatTranslation::customerLanguage($thread->conversation);
        $thread->ai_assistant = json_encode($data, JSON_UNESCAPED_UNICODE);
        $thread->ai_assistant_updated_at = now();
    }
}
