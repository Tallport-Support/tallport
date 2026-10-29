<?php

namespace App\Ai;

use App\Ai\Agents\ThreadTranslator;
use App\Ai\Agents\TallportAgent;
use App\Thread;

/**
 * Translations of customers' messages, by language, in threads.ai_assistant:
 * {"language": "nl", "translations": {"en": "..."}, "same": ["nl"]}.
 * "same": languages the message is already in (no translation needed).
 * "forced": languages it was translated into on request (shown even to users who read it).
 * Why there is none is kept too: "no_text" (nothing to translate),
 * "errors" (by language, until it works), "truncated" (only the start),
 * "bad_answer": languages whose error was the AI's answer, not its being unavailable
 * (not tried again by itself, App\Jobs\AiRetryTranslations), "retries": its tries by itself,
 * by language.
 */
class Translations
{
    public static function get(Thread $thread, $language)
    {
        $data = Summaries::data($thread);

        return $data['translations'][$language] ?? null;
    }

    /**
     * Previews in the user's language for a page of a list: each conversation's latest message's
     * translation, where there is one (none is asked for here). [conversation id => text]
     */
    public static function previews($conversations, $user)
    {
        $conversations = self::listed($conversations);
        $ids = $conversations->pluck('id')->all();
        if (!$ids) {
            return [];
        }
        $latest = Thread::whereIn('conversation_id', $ids)->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)->groupBy('conversation_id')->selectRaw('max(id) as id')->pluck('id');
        $previews = [];
        foreach (Thread::whereIn('id', $latest)->whereNotNull('ai_assistant')->get() as $thread) {
            $conversation = $conversations->firstWhere('id', $thread->conversation_id);
            $language = $conversation ? Settings::language($conversation->mailbox_cached ?? $conversation->mailbox, $user) : null;
            // Not for an agent who reads the message's language.
            $translation = $language && !self::readAsWritten($thread, $user, $language) ? self::get($thread, $language) : null;
            if ($translation !== null && trim($translation) !== '') {
                $previews[$thread->conversation_id] = \Helper::textPreview(self::isHtml($thread, $language) ? \Helper::htmlToText($translation) : $translation, \App\Conversation::PREVIEW_MAXLENGTH);
            }
        }

        return $previews;
    }

    /**
     * Subjects in the user's language for a page of a list: translated with each conversation's
     * first message. [conversation id => subject]
     */
    public static function subjects($conversations, $user)
    {
        $conversations = self::listed($conversations);
        if ($conversations->isEmpty()) {
            return [];
        }
        $first = Thread::whereIn('conversation_id', $conversations->pluck('id')->all())->groupBy('conversation_id')->selectRaw('min(id) as id')->pluck('id');
        $subjects = [];
        foreach (Thread::whereIn('id', $first)->whereNotNull('ai_assistant')->get() as $thread) {
            $conversation = $conversations->firstWhere('id', $thread->conversation_id);
            $language = $conversation ? Settings::language($conversation->mailbox_cached ?? $conversation->mailbox, $user) : null;
            $subject = $language && !self::readAsWritten($thread, $user, $language) ? (Summaries::data($thread)['subjects'][$language] ?? null) : null;
            if ($subject !== null && $subject !== '') {
                $subjects[$thread->conversation_id] = $subject;
            }
        }

        return $subjects;
    }

    /**
     * A list's conversations: a page of them (a paginator) or a collection.
     */
    protected static function listed($conversations)
    {
        return collect($conversations instanceof \Illuminate\Contracts\Pagination\Paginator ? $conversations->items() : $conversations);
    }

    /**
     * A message's translation for the user reading it: a customer's message, or a
     * reply made from a draft with the draft's translation. Asks for a missing one.
     * Not for a customer's message in a language the user reads, unless they asked.
     *
     * @return array wanted (bool), language (the user's), translation (or null)
     */
    public static function forThread(Thread $thread, $user)
    {
        $result = ['wanted' => false, 'language' => null, 'translation' => null];
        $language = $thread->conversation ? Settings::language($thread->conversation->mailbox, $user) : null;
        $as_written = self::readAsWritten($thread, $user, $language);
        // Also a message translated on request (forceTranslate()) where translations are off.
        $requested = in_array($thread->type, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE]) && $thread->ai_assistant && $thread->conversation
            && !$as_written && self::get($thread, $language);
        if ($thread->state == Thread::STATE_DRAFT || $as_written
            || !(($thread->type == Thread::TYPE_CUSTOMER && self::isWanted($thread)) || ($thread->type == Thread::TYPE_MESSAGE && $thread->ai_assistant) || $requested)
        ) {
            return $result;
        }
        $result['wanted'] = true;
        $result['language'] = $language;
        $result['translation'] = self::get($thread, $result['language']);
        if ($thread->type == Thread::TYPE_CUSTOMER && self::isMissing($thread, $result['language'])) {
            \App\Jobs\AiTranslateThread::request($thread, $result['language']);
        }

        return $result;
    }

    /**
     * The language a customer's message is in: as detected, else the customer's.
     */
    public static function messageLanguage(Thread $thread)
    {
        return Summaries::data($thread)['language'] ?? ($thread->customer->language ?? null);
    }

    /**
     * Whether a user reads a customer's message as it is written: in their own language or one
     * they need no translation of, and not translated for them on request (forceTranslate()).
     */
    public static function readAsWritten(Thread $thread, $user, $language)
    {
        return $user && $thread->type == Thread::TYPE_CUSTOMER
            && Settings::reads($user, self::messageLanguage($thread))
            && !in_array($language, (array) (Summaries::data($thread)['forced'] ?? []), true);
    }

    public static function isWanted(Thread $thread)
    {
        return Settings::isConfigured()
            && !$thread->getMeta('chat_pending')
            && $thread->type == Thread::TYPE_CUSTOMER
            && $thread->state == Thread::STATE_PUBLISHED
            && $thread->conversation
            && (Settings::enabled('translations', $thread->conversation->mailbox) || ChatTranslation::isOn($thread->conversation));
    }

    /**
     * Whether the thread still has to be translated into a language.
     */
    public static function isMissing(Thread $thread, $language)
    {
        if ($thread->getMeta('chat_pending')) {
            return false;
        }

        $data = Summaries::data($thread);

        return !isset($data['translations'][$language])
            && !in_array($language, (array) ($data['same'] ?? []))
            && ($data['language'] ?? null) !== $language
            && empty($data['no_text']);
    }

    /**
     * Why a message has no translation into a language, for the note under
     * it: ['same', detected language] (taken to be in the language already),
     * ['no_text'], ['error', message], a limit (['budget'], ['customer_limit']), ['waiting'], or null (nothing to say:
     * it is in that language).
     */
    public static function reason(Thread $thread, $language)
    {
        $data = Summaries::data($thread);
        if (!empty($data['no_text'])) {
            return ['no_text'];
        }
        if (isset($data['errors'][$language])) {
            $error = $data['errors'][$language];

            return in_array($error, [self::LIMIT_BUDGET, self::LIMIT_CUSTOMER]) ? [$error] : ['error', $error];
        }
        if (!self::isMissing($thread, $language)) {
            $detected = $data['language'] ?? null;

            return $detected && $detected !== $language ? ['same', $detected] : null;
        }

        return ['waiting'];
    }

    /**
     * Whether a user may have a message translated on request (the message's menu):
     * a customer's message or a reply (notes stay with the team), not yet translated
     * into the user's language.
     */
    public static function canForce(Thread $thread, $user)
    {
        return Settings::isConfigured()
            && $thread->state == Thread::STATE_PUBLISHED
            && in_array($thread->type, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            && $thread->conversation
            && (!self::get($thread, $language = Settings::language($thread->conversation->mailbox, $user)) || self::readAsWritten($thread, $user, $language));
    }

    /**
     * Translate a message now, on request, into the user's language: also a reply,
     * and also when an earlier translation took it to be in that language already.
     * $on_activity: called on each sign of the AI's work.
     *
     * @return string|null the translation; null when it is in that language already
     */
    public static function forceTranslate(Thread $thread, $user, ?callable $on_activity = null)
    {
        if ($thread->getMeta('chat_pending')) {
            return null;
        }

        $language = Settings::language($thread->conversation->mailbox, $user);
        Summaries::updateData($thread, function ($data) use ($language) {
            // Shown in a language the user reads too (readAsWritten()).
            $data['forced'] = array_values(array_unique(array_merge((array) ($data['forced'] ?? []), [$language])));
            $data['same'] = array_values(array_diff((array) ($data['same'] ?? []), [$language]));
            if (!$data['same']) {
                unset($data['same']);
            }
            unset($data['no_text']);

            return $data;
        });

        return self::translate($thread, $language, $on_activity);
    }

    /**
     * Limits that keep a message from being translated now (kept as its error, and tried
     * again when someone reads it): the mailbox's tokens for today, the customer's per hour.
     */
    const LIMIT_BUDGET = 'budget';
    const LIMIT_CUSTOMER = 'customer_limit';

    public static function limit(Thread $thread)
    {
        $conversation = $thread->conversation;
        if (!Settings::withinBudget($conversation->mailbox)) {
            return self::LIMIT_BUDGET;
        }
        $per_hour = Settings::translationsPerCustomerHour();
        if ($per_hour && $conversation->customer_id && Usage::customerTranslationsLastHour($conversation->customer_id) >= $per_hour) {
            return self::LIMIT_CUSTOMER;
        }

        return null;
    }

    public static function limited(Thread $thread, $language, $limit)
    {
        Summaries::updateData($thread, function ($data) use ($language, $limit) {
            $data['errors'][$language] = $limit;

            return $data;
        });
    }

    /**
     * Keep why translating failed (it's tried again when someone reads it).
     */
    public static function failed(Thread $thread, $language, \Throwable $e)
    {
        $message = Errors::message($e, 'Translation of thread '.$thread->id.':');
        $bad_answer = !Errors::unavailable($e);
        Summaries::updateData($thread, function ($data) use ($language, $message, $bad_answer) {
            $data['errors'][$language] = $message;
            $data['bad_answer'] = array_values(array_diff((array) ($data['bad_answer'] ?? []), [$language]));
            if ($bad_answer) {
                $data['bad_answer'][] = $language;
            }
            if (!$data['bad_answer']) {
                unset($data['bad_answer']);
            }

            return $data;
        });
    }

    /**
     * Failed translations are tried again by themselves for this many days, each at most this
     * many times (App\Jobs\AiRetryTranslations): then when someone opens the conversation.
     */
    const RETRY_DAYS = 7;
    const RETRY_TIMES = 6;

    /**
     * Customers' messages of the last days not translated because the AI was unavailable (or
     * failed before that was told apart): [[thread, language]], the latest first. Not those
     * over a limit (tried again when someone reads them), nor an answer that couldn't be used,
     * nor one tried enough.
     */
    public static function failedRecently($limit = 200)
    {
        $failed = [];
        $threads = Thread::where('type', Thread::TYPE_CUSTOMER)
            ->where('state', Thread::STATE_PUBLISHED)
            ->where('created_at', '>=', now()->subDays(self::RETRY_DAYS))
            ->where('ai_assistant', 'like', '%"errors"%')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
        foreach ($threads as $thread) {
            $data = Summaries::data($thread);
            foreach ((array) ($data['errors'] ?? []) as $language => $error) {
                if (in_array($error, [self::LIMIT_BUDGET, self::LIMIT_CUSTOMER]) || in_array($language, (array) ($data['bad_answer'] ?? []))
                    || ($data['retries'][$language] ?? 0) >= self::RETRY_TIMES
                ) {
                    continue;
                }
                if (self::isWanted($thread) && self::isMissing($thread, $language)) {
                    $failed[] = [$thread, $language];
                }
            }
        }

        return $failed;
    }

    /**
     * A failed translation tried again by itself (failedRecently()): counted.
     */
    public static function retrying(Thread $thread, $language)
    {
        Summaries::updateData($thread, function ($data) use ($language) {
            $data['retries'][$language] = ($data['retries'][$language] ?? 0) + 1;

            return $data;
        }, false);
    }

    /**
     * Tags kept when a message goes to translation as HTML, with the attributes they keep.
     */
    const HTML_TAGS = [
        'p' => [], 'br' => [], 'div' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'pre' => [], 'code' => [], 'hr' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => [], 'th' => [],
        'a' => ['href'], 'img' => ['src', 'alt', 'width', 'height'],
    ];

    /**
     * A message as simple HTML for translating it as it looks (links, images, lists, tables
     * such as a signature's business card): other tags unwrapped, styles and scripts gone,
     * links and images only from http(s) (mailto/tel links too).
     */
    public static function sourceHtml(Thread $thread)
    {
        $html = (string) $thread->getCleanBody();
        if (trim($html) === '') {
            return '';
        }
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div id="tallport-source">'.$html.'</div>', LIBXML_NONET);
        libxml_clear_errors();
        $root = $document->getElementById('tallport-source');
        if (!$root) {
            return '';
        }
        $clean = function (\DOMNode $node) use (&$clean, $document) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof \DOMComment) {
                    $node->removeChild($child);
                    continue;
                }
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['style', 'script', 'head', 'meta', 'title', 'link', 'noscript', 'template', 'svg'])) {
                    $node->removeChild($child);
                    continue;
                }
                $clean($child);
                if (!array_key_exists($tag, self::HTML_TAGS)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $value = trim($attribute->value);
                    $keep = in_array($attribute->name, self::HTML_TAGS[$tag])
                        && ($attribute->name != 'href' || preg_match('#^(https?:|mailto:|tel:)#i', $value))
                        && ($attribute->name != 'src' || preg_match('#^https?:#i', $value));
                    if (!$keep) {
                        $child->removeAttribute($attribute->name);
                    }
                }
                // An image that can't be shown here (e.g. attached, cid:): its text, if any.
                if ($tag == 'img' && !$child->hasAttribute('src')) {
                    $node->replaceChild($document->createTextNode((string) $child->getAttribute('alt')), $child);
                }
            }
        };
        $clean($root);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim(preg_replace(['/[ \t]+/', "/\n\s*\n+/"], [' ', "\n"], $result));
    }

    /**
     * Whether a translation is HTML (kept the message's links, images and layout), or
     * plain text (older translations).
     */
    public static function isHtml(Thread $thread, $language)
    {
        return in_array($language, (array) (Summaries::data($thread)['html'] ?? []));
    }

    public static function translate(Thread $thread, $language, ?callable $on_activity = null)
    {
        $text = Summaries::text($thread);
        $truncated = false;
        $as_html = false;
        $answer = null;
        if ($text !== '') {
            if (mb_strlen(trim((string) $thread->getBodyAsText())) > Summaries::MAX_THREAD_CHARS) {
                $truncated = true;
            }
            // As it looks (HTML) where that fits, else as text.
            $html = self::sourceHtml($thread);
            $as_html = $html !== '' && mb_strlen($html) <= Summaries::MAX_THREAD_CHARS * 3;
            $deadline = microtime(true) + 120;
            $context = ConversationContext::build($thread->conversation, $language, false, $thread->id, $deadline, $thread, $on_activity);
            // The conversation's first message: its subject too (shown in lists).
            $subject = $thread->type == Thread::TYPE_CUSTOMER && trim((string) $thread->conversation->subject) !== ''
                && $thread->id == Thread::where('conversation_id', $thread->conversation_id)->min('id');
            [$answer] = (new ThreadTranslator($language, $as_html, $subject))->recordFor(Usage::FEATURE_TRANSLATION, $thread->conversation)->streamJson(
                $context['prompt']."\n\n".($subject ? TallportAgent::data('subject', mb_substr((string) $thread->conversation->subject, 0, 1000))."\n\n" : '').TallportAgent::data('message', $as_html ? $html : $text),
                $thread->type == Thread::TYPE_CUSTOMER ? self::broadcaster($thread->conversation, $language, fn ($answer) => [$thread->id => [$answer['translation'] ?? '', $as_html]]) : null, $deadline, $on_activity
            );
        }
        $data = Summaries::updateData($thread, function ($data) use ($language, $text, $truncated, $as_html, $answer) {
            unset($data['errors'][$language]);
            if (empty($data['errors'])) {
                unset($data['errors']);
            }
            if ($text === '') {
                $data['no_text'] = true;

                return $data;
            }
            if ($truncated) {
                $data['truncated'] = true;
            }
            $data['language'] = Settings::detectedLanguage($answer['detected_language'] ?? '') ?: (strtolower(trim((string) ($answer['detected_language'] ?? ''))) ?: ($data['language'] ?? null));
            if ($answer['same_language']) {
                $data['same'] = array_values(array_unique(array_merge((array) ($data['same'] ?? []), [$language])));
            } else {
                $data['translations'][$language] = trim((string) $answer['translation']);
                if (trim((string) ($answer['subject'] ?? '')) !== '') {
                    $data['subjects'][$language] = mb_substr(trim(strip_tags((string) $answer['subject'])), 0, 255);
                }
                $data['html'] = array_values(array_diff((array) ($data['html'] ?? []), [$language]));
                if ($as_html) {
                    $data['html'][] = $language;
                }
                if (!$data['html']) {
                    unset($data['html']);
                }
            }

            return $data;
        });

        // The customer's language, while they have none (replies go out in it).
        if ($thread->type == Thread::TYPE_CUSTOMER && !$thread->isBounce() && !$thread->getMeta('app_text')) {
            ChatTranslation::setCustomerLanguage($thread->customer, $data['language'] ?? null);
        }

        return $data['translations'][$language] ?? null;
    }

    /**
     * Translations being written are passed on to open conversations at most this often (seconds;
     * they poll every few seconds, every second while translations come in: public/js/realtime.js).
     */
    const STREAM_INTERVAL = 0.5;

    /**
     * Pass translations on as they're written (RealtimeConvTranslating), throttled: for
     * TallportAgent::streamJson(). $translations: the answer so far => thread_id => [text, html].
     */
    public static function broadcaster(\App\Conversation $conversation, $language, callable $translations)
    {
        $throttle = new StreamThrottle(self::STREAM_INTERVAL);

        return function ($answer) use ($conversation, $language, $translations, $throttle) {
            $written = array_filter($translations($answer), fn ($translation) => trim((string) $translation[0]) !== '');
            if ($written && $throttle->ready()) {
                \App\Events\RealtimeConvTranslating::dispatchSelf($conversation, $language, $written);
            }
        };
    }

    /**
     * A translation made with others (a chat's messages together, App\Ai\ChatTranslation):
     * plain text, or null when the message is in that language already.
     */
    public static function store(Thread $thread, $language, $detected, $translation)
    {
        if ($translation !== null) {
            $translation = trim((string) $translation);
            if ($translation === '') {
                throw new \RuntimeException(__('The AI\'s answer could not be read.'));
            }
        }
        Summaries::updateData($thread, function ($data) use ($language, $detected, $translation) {
            unset($data['errors'][$language]);
            if (empty($data['errors'])) {
                unset($data['errors']);
            }
            $data['language'] = $detected ?: ($data['language'] ?? null);
            if ($translation === null) {
                $data['same'] = array_values(array_unique(array_merge((array) ($data['same'] ?? []), [$language])));
            } else {
                $data['translations'][$language] = $translation;
                $data['html'] = array_values(array_diff((array) ($data['html'] ?? []), [$language]));
                if (!$data['html']) {
                    unset($data['html']);
                }
            }

            return $data;
        });
    }
}
