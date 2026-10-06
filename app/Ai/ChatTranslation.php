<?php

namespace App\Ai;

use App\Ai\Agents\ChatTranslator;
use App\Ai\Agents\ReplyTranslator;
use App\Ai\Agents\TallportAgent;
use App\Conversation;
use App\Thread;
use Illuminate\Support\Facades\DB;

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
     * a customer's message (only the first time, so that one message in another language
     * doesn't switch it, and never over an agent's choice).
     */
    public static function setCustomerLanguage(Conversation $conversation, $language, $by_user = false)
    {
        $data = Summaries::data($conversation);
        if (!$by_user && (!empty($data['language_by']) || !self::isOn($conversation))) {
            return;
        }
        $data['language'] = (string) $language;
        $data['language_by'] = $by_user ? 'user' : 'detected';
        // Not touching updated_at: the conversation didn't change.
        DB::table('conversations')->where('id', $conversation->id)->update(['ai_assistant' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        $conversation->ai_assistant = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * An agent's reply (HTML) in the customer's language: ['html' => translation], or
     * ['same' => true] when it's in that language already. Throws when the AI fails or the
     * mailbox's tokens are used up.
     */
    public static function translateReply(Conversation $conversation, $html, $user)
    {
        if (!Settings::withinBudget($conversation->mailbox)) {
            throw new \RuntimeException(__('This mailbox has used its AI tokens for today.'));
        }
        $language = self::customerLanguage($conversation);
        $response = (new ReplyTranslator($language))->prompt(implode("\n\n", [
            TallportAgent::data('chat', self::context($conversation)),
            TallportAgent::data('reply', $html),
        ]));
        Usage::record($response, Usage::FEATURE_REPLY_TRANSLATION, $conversation, null, $user ? $user->id : null);

        $translation = trim((string) $response['translation']);
        if ($response['same_language'] || $translation === '') {
            return ['same' => true];
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
            $response = (new ChatTranslator($language))->prompt(implode("\n\n", [
                TallportAgent::data('earlier_chat', self::context($conversation, $batch->first()->id)),
                TallportAgent::data('messages', $batch->map(fn (Thread $thread) => ['id' => $thread->id, 'text' => Summaries::text($thread)])->all()),
            ]));
        } catch (\Throwable $e) {
            \Helper::logException($e, '[AI Assistant] Translation of conversation '.$conversation->id.':');
            $batch->each(fn (Thread $thread) => Translations::failed($thread, $language, $e));

            return $threads->all();
        }
        Usage::record($response, Usage::FEATURE_TRANSLATION, $conversation, null, null, count($batch));

        $detected = strtolower(trim((string) $response['detected_language'])) ?: null;
        if ($detected && Settings::isLanguage($detected)) {
            self::setCustomerLanguage($conversation, $detected);
        }
        $translated = collect((array) $response['messages'])->keyBy(fn ($message) => (int) ($message['id'] ?? 0));
        foreach ($batch as $thread) {
            $message = $translated[$thread->id] ?? null;
            if (!$message) {
                Translations::failed($thread, $language, new \RuntimeException(__('The AI Assistant left this message out.')));
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
