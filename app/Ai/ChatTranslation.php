<?php

namespace App\Ai;

use App\Ai\Agents\ChatTranslator;
use App\Ai\Agents\ReplyTranslator;
use App\Ai\Agents\TallportAgent;
use App\Conversation;
use App\Customer;
use App\Thread;

/**
 * Conversations translated both ways, chats and emails (switched on separately per mailbox,
 * in the mailbox's AI settings): the agent reads the customer's messages in their own language
 * (App\Ai\Translations) and writes replies in it, which go to the customer in the customer's
 * language after a preview (livewire/conversation-composer). "Send as Written" sends a reply as
 * it is. Only a reply's text is translated: an email's signature and quoted history are added
 * when it's sent, as they are, and its subject stays the conversation's.
 *
 * The customer's language is on their profile (customers.language): the one first detected in
 * their messages, or as an agent set it.
 */
class ChatTranslation
{
    /**
     * The conversation's latest messages sent along for context (and at most this much text).
     */
    const CONTEXT_MESSAGES = 10;

    const CONTEXT_CHARS = 3000;

    /**
     * Whether the conversation is translated: a chat (Telegram, Nostr) where the mailbox
     * translates chats, an email conversation where it translates emails.
     */
    public static function isOn(Conversation $conversation)
    {
        if (!Settings::isConfigured()) {
            return false;
        }
        if ($conversation->hasChannel()) {
            return Settings::chatTranslation($conversation->mailbox);
        }

        return $conversation->type == Conversation::TYPE_EMAIL && Settings::emailTranslation($conversation->mailbox);
    }

    /**
     * The language the customer reads (replies go out in it), from their profile, if known.
     */
    public static function customerLanguage(Conversation $conversation)
    {
        return $conversation->customer->language ?? null;
    }

    /**
     * The language the agent writes in.
     */
    public static function agentLanguage(Conversation $conversation, $user)
    {
        return Settings::language($conversation->mailbox, $user);
    }

    /**
     * Whether the agent's replies are translated: on for the conversation, and the customer's
     * language known and the agent's not one the customer reads.
     */
    public static function needed(Conversation $conversation, $user)
    {
        if (!self::isOn($conversation)) {
            return false;
        }
        $language = self::customerLanguage($conversation);
        $agent_language = self::agentLanguage($conversation, $user);

        return $language && $language !== $agent_language && !in_array($agent_language, (array) $conversation->customer->languages, true);
    }

    /**
     * The customer's language (their profile's): as an agent chose it, or as detected in their
     * message, kept only while they have none.
     */
    public static function setCustomerLanguage($customer, $language, $by_user = false)
    {
        if (!$customer || !Settings::isLanguage($language) || (!$by_user && $customer->language)) {
            return;
        }
        if (!$by_user) {
            // Another detection (a message at the same time) may have been first.
            Customer::where('id', $customer->id)->whereNull('language')->update(['language' => $language]);
            $customer->language = Customer::where('id', $customer->id)->value('language');
            $customer->syncOriginalAttribute('language');

            return;
        }
        $customer->language = $language;
        $customer->save();
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
            TallportAgent::data('conversation', self::context($conversation)),
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
        self::setCustomerLanguage($conversation->customer, $detected);
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
     * The conversation's latest messages (oldest first, notes left out), for the translation's
     * context; those before a message, if given. An email's quoted history is left out: a
     * customer's reply is cut where the quote starts when it's fetched
     * (FetchEmails::separateReply()), an agent's gets it only when it's sent.
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
