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
     * Customer meta: their language came from their browser, not yet from what they wrote.
     */
    const FROM_BROWSER = 'language_from_browser';

    /**
     * The conversation's latest messages sent along for context (and at most this much text).
     */
    const CONTEXT_MESSAGES = ConversationContext::MAX_MESSAGES;

    const CONTEXT_CHARS = ConversationContext::MAX_HISTORY_CHARS;

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
    /**
     * A customer's language from their browser (App\WebChat: its first language), for one without
     * any: what they write, once detected, replaces it (setCustomerLanguage()); an agent's choice
     * stands. Only the first language: browsers add English by default.
     */
    public static function setCustomerLanguageFromBrowser($customer, $tag)
    {
        $language = \App\AutoReply\AutoReplies::fromLanguageTag((string) $tag, array_keys(Settings::LANGUAGES));
        if (!$customer || $customer->language || !$language) {
            return;
        }
        $customer->language = $language;
        $customer->setMeta(self::FROM_BROWSER, true);
        $customer->save();
    }

    public static function setCustomerLanguage($customer, $language, $by_user = false)
    {
        // Taken from their browser (a website chat) until detected in what they write.
        $from_browser = $customer && $customer->language && $customer->getMeta(self::FROM_BROWSER);
        if (!$customer || !Settings::isLanguage($language) || (!$by_user && $customer->language && !$from_browser)) {
            return;
        }
        if ($from_browser) {
            $customer->setMeta(self::FROM_BROWSER, null);
            $customer->language = $language;
            $customer->save();

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
     * A reply's translation is waited for (its preview) at most this many seconds: a reply is
     * translated in a few, so the AI isn't answering, and the reply can be sent as written. Also
     * within the time the server and a proxy in front of it (as Cloudflare's 100) give a request.
     */
    const REPLY_SECONDS = 45;

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
        $deadline = microtime(true) + self::REPLY_SECONDS;
        $context = ConversationContext::build($conversation, $language, false, null, $deadline);
        [$answer] = (new ReplyTranslator($language))->recordFor(Usage::FEATURE_REPLY_TRANSLATION, $conversation, null, $user ? $user->id : null)->streamJson(
            $context['prompt']."\n\n".TallportAgent::data('reply', $html),
            $on_translation ? fn ($answer) => $on_translation((string) ($answer['translation'] ?? '')) : null, $deadline
        );

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
            $deadline = microtime(true) + 120;
            $context = ConversationContext::build($conversation, $language, false, $batch->first()->id, $deadline, $batch->last());
            [$answer] = (new ChatTranslator($language, $ids))->recordFor(Usage::FEATURE_TRANSLATION, $conversation, null, null, count($batch))->streamJson(
                $context['prompt']."\n\n".TallportAgent::data('messages', $batch->map(fn (Thread $thread) => ['id' => $thread->id, 'text' => Summaries::text($thread)])->all()),
                Translations::broadcaster($conversation, $language, fn ($answer) => collect((array) ($answer['messages'] ?? []))
                    ->filter(fn ($message) => is_array($message) && in_array((int) ($message['id'] ?? 0), $ids) && empty($message['same_language']))
                    ->mapWithKeys(fn ($message) => [(int) $message['id'] => [(string) ($message['translation'] ?? ''), false]])
                    ->all()),
                $deadline
            );
        } catch (\Throwable $e) {
            Errors::report($e, 'Translation of conversation '.$conversation->id.':');
            $batch->each(fn (Thread $thread) => Translations::failed($thread, $language, $e));

            return $threads->all();
        }

        $detected = Settings::detectedLanguage($answer['detected_language'] ?? '');
        // Only from what the customer wrote (not their app's text, such as shared logs).
        if ($batch->contains(fn (Thread $thread) => !$thread->getMeta('app_text'))) {
            self::setCustomerLanguage($conversation->customer, $detected);
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
     * The conversation's latest messages (oldest first), for the translation's
     * context; those before a message, if given. An email's quoted history is left out: a
     * customer's reply is cut where the quote starts when it's fetched
     * (FetchEmails::separateReply()), an agent's gets it only when it's sent.
     */
    public static function context(Conversation $conversation, $before_id = null)
    {
        return array_map(fn ($message) => [
            'from' => $message['type'] == 'customer_to_staff' ? 'customer' : ($message['type'] == 'internal_note' ? 'internal_note' : 'support'),
            'text' => $message['body'],
        ], ConversationContext::history($conversation, null, $before_id)['messages']);
    }
}
