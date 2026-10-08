<?php

namespace App\Ai;

use App\Ai\Agents\ChatTranslator;
use App\Ai\Agents\ReplyTranslator;
use App\Ai\Agents\TallportAgent;
use App\Conversation;
use App\Thread;

/**
 * Chats translated both ways (Manage » Settings » AI Assistant, per mailbox): the agent reads
 * the customer's messages in their own language (App\Ai\Translations) and writes replies in it,
 * which go to the customer in the conversation's language after a preview
 * (livewire/conversation-composer). "Send as Written" sends a reply as it is.
 *
 * The conversation's language is the one first detected in the customer's messages, kept in
 * conversations.ai_assistant ("language", "language_by": detected, or user when an agent chose it; an empty language: not translated).
 */
class ChatTranslation
{
    /**
     * The chat's latest messages sent along for context (and at most this much text).
     */
    const CONTEXT_MESSAGES = 10;

    const CONTEXT_CHARS = 3000;

    public static function isOn(Conversation $conversation)
    {
        return $conversation->hasChannel() && Settings::isConfigured() && Settings::chatTranslation($conversation->mailbox);
    }

    /**
     * The language the customer writes in (and replies go out in), if known.
     */
    public static function customerLanguage(Conversation $conversation)
    {
        return Summaries::data($conversation)['language'] ?? null;
    }

    /**
     * The language the agent writes in.
     */
    public static function agentLanguage(Conversation $conversation, $user)
    {
        return Settings::language($conversation->mailbox, $user);
    }

    /**
     * Whether the agent's replies are translated: on for the chat, and the customer's
     * language known and not the agent's.
     */
    public static function needed(Conversation $conversation, $user)
    {
        if (!self::isOn($conversation)) {
            return false;
        }
        $language = self::customerLanguage($conversation);

        return $language && $language !== self::agentLanguage($conversation, $user);
    }

    /**
     * The conversation's language: by an agent (empty: replies not translated), or detected in
     * the customer's messages: the first one's, then another once two messages in a row are in
     * it (so a "/start" or one message in another language doesn't decide it); never over an
     * agent's choice.
     */
    public static function setCustomerLanguage(Conversation $conversation, $language, $by_user = false)
    {
        Summaries::updateData($conversation, function ($data) use ($conversation, $language, $by_user) {
            if (!$by_user && (($data['language_by'] ?? '') == 'user' || !self::isOn($conversation))) {
                return null;
            }
            $chosen_language = $language;
            if (!$by_user && !empty($data['language_by'])) {
                $pending = $data['language_next'] ?? null;
                unset($data['language_next']);
                if ($language !== ($data['language'] ?? null) && $language !== $pending) {
                    $data['language_next'] = (string) $language;
                    $chosen_language = $data['language'] ?? null;
                }
            }
            $data['language'] = (string) $chosen_language;
            $data['language_by'] = $by_user ? 'user' : 'detected';

            return $data;
        }, false);
    }

    /**
     * An agent's reply (HTML) in the customer's language: ['html' => translation], or
     * ['same' => true] when it's in that language already. Throws when the AI fails or the
     * mailbox's tokens are used up. $on_translation: the translation so far, as it's written.
     */
    public static function translateReply(Conversation $conversation, $html, $user, ?callable $on_translation = null)
    {
        if (!Settings::withinBudget($conversation->mailbox)) {
            throw new \RuntimeException(__('This mailbox has used its AI tokens for today.'));
        }
        $language = self::customerLanguage($conversation);
        [$answer] = (new ReplyTranslator($language))->recordFor(Usage::FEATURE_REPLY_TRANSLATION, $conversation, null, $user ? $user->id : null)->streamJson(implode("\n\n", array_filter([
            TallportAgent::glossary($conversation->mailbox),
            TallportAgent::data('chat', self::context($conversation)),
            TallportAgent::data('reply', $html),
        ])), $on_translation ? fn ($answer) => $on_translation((string) ($answer['translation'] ?? '')) : null);

        $translation = trim((string) ($answer['translation'] ?? ''));
        if ($answer['same_language']) {
            return ['same' => true];
        }
        // "Translated automatically", in the customer's language, where the mailbox says so.
        $note = trim(strip_tags((string) ($answer['note'] ?? '')));
        if ($note !== '' && Settings::translationNote($conversation->mailbox)) {
            $translation .= '<p><em>('.e(trim($note, '() ')).')</em></p>';
        }

        return ['html' => $translation];
    }

    /**
     * A customer's messages arrive together in a chat: they're translated together, a few
     * seconds after the latest (AiTranslateThread), at most this many in one call.
     */
    const BATCH_MESSAGES = 20;

    const BATCH_DELAY = 5;

    /**
     * Translate a chat's customer messages that have no translation into a language yet, in
     * one call with the chat's earlier messages for context. Within the mailbox's tokens and
     * the customer's messages per hour (the latest ones first); the rest say why.
     *
     * @return Thread[] the messages dealt with
     */
    public static function translateIncoming(Conversation $conversation, $language)
    {
        $threads = $conversation->threads()
            ->where('type', Thread::TYPE_CUSTOMER)
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('id', 'desc')
            ->limit(self::BATCH_MESSAGES)
            ->get()
            ->filter(fn (Thread $thread) => Translations::isMissing($thread, $language))
            ->values();
        if (!count($threads)) {
            return [];
        }
        if ($limit = Translations::limit($threads->first())) {
            if ($limit == Translations::LIMIT_BUDGET) {
                $threads->each(fn (Thread $thread) => Translations::limited($thread, $language, $limit));

                return $threads->all();
            }
        }
        // The customer's messages per hour: the latest ones while there's room.
        $per_hour = Settings::translationsPerCustomerHour();
        if ($per_hour && $conversation->customer_id) {
            $room = max(0, $per_hour - Usage::customerTranslationsLastHour($conversation->customer_id));
            $threads->slice($room)->each(fn (Thread $thread) => Translations::limited($thread, $language, Translations::LIMIT_CUSTOMER));
            if (!$room) {
                return $threads->all();
            }
            $batch = $threads->take($room)->reverse()->values();
        } else {
            $batch = $threads->reverse()->values();
        }

        try {
            // Open pages show the translations as they're written.
            $ids = $batch->pluck('id')->all();
            [$answer] = (new ChatTranslator($language, $ids))->recordFor(Usage::FEATURE_TRANSLATION, $conversation, null, null, count($batch))->streamJson(implode("\n\n", array_filter([
                TallportAgent::glossary($conversation->mailbox),
                TallportAgent::data('earlier_chat', self::context($conversation, $batch->first()->id)),
                TallportAgent::data('messages', $batch->map(fn (Thread $thread) => ['id' => $thread->id, 'text' => Summaries::text($thread)])->all()),
            ])), Translations::broadcaster($conversation, $language, fn ($answer) => collect((array) ($answer['messages'] ?? []))
                ->filter(fn ($message) => is_array($message) && in_array((int) ($message['id'] ?? 0), $ids) && empty($message['same_language']))
                ->mapWithKeys(fn ($message) => [(int) $message['id'] => [(string) ($message['translation'] ?? ''), false]])
                ->all()));
        } catch (\Throwable $e) {
            Errors::report($e, 'Translation of conversation '.$conversation->id.':');
            $batch->each(fn (Thread $thread) => Translations::failed($thread, $language, $e));

            return $threads->all();
        }

        $detected = Settings::detectedLanguage($answer['detected_language'] ?? '');
        if ($detected) {
            self::setCustomerLanguage($conversation, $detected);
        }
        $translated = collect((array) ($answer['messages'] ?? []))->filter(fn ($message) => is_array($message))->keyBy(fn ($message) => (int) ($message['id'] ?? 0));
        foreach ($batch as $thread) {
            $message = $translated[$thread->id] ?? null;
            if (!$message) {
                Translations::failed($thread, $language, new \RuntimeException(__('The AI left this message out.')));
                continue;
            }
            Translations::store($thread, $language, $detected, !empty($message['same_language']) ? null : ($message['translation'] ?? ''));
        }

        return $threads->all();
    }

    /**
     * The chat's latest messages (oldest first, notes left out), for the translation's
     * context; those before a message, if given.
     */
    public static function context(Conversation $conversation, $before_id = null)
    {
        $threads = $conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->when($before_id, fn ($query) => $query->where('id', '<', $before_id))
            ->orderBy('id', 'desc')
            ->limit(self::CONTEXT_MESSAGES)
            ->get();
        $messages = [];
        $chars = 0;
        foreach ($threads as $thread) {
            $text = mb_substr(Summaries::text($thread), 0, self::CONTEXT_CHARS);
            $chars += mb_strlen($text);
            if ($messages && $chars > self::CONTEXT_CHARS) {
                break;
            }
            $messages[] = ['from' => $thread->type == Thread::TYPE_CUSTOMER ? 'customer' : 'support', 'text' => $text];
        }

        return array_reverse($messages);
    }
}
