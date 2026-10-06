<?php

namespace App\Ai;

use App\Ai\Agents\ThreadTranslator;
use App\Ai\Agents\TallportAgent;
use App\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Translations of customers' messages, by language, in threads.ai_assistant:
 * {"language": "nl", "translations": {"en": "..."}, "same": ["nl"]}.
 * "same": languages the message is already in (no translation needed).
 * Why there is none is kept too: "no_text" (nothing to translate),
 * "errors" (by language, until it works), "truncated" (only the start).
 */
class Translations
{
    public static function get(Thread $thread, $language)
    {
        $data = Summaries::data($thread);

        return $data['translations'][$language] ?? null;
    }

    /**
     * A message's translation for the user reading it: a customer's message, or a
     * reply made from a draft with the draft's translation. Asks for a missing one.
     *
     * @return array wanted (bool), language (the user's), translation (or null)
     */
    public static function forThread(Thread $thread, $user)
    {
        $result = ['wanted' => false, 'language' => null, 'translation' => null];
        // Also a message translated on request (forceTranslate()) where translations are off.
        $requested = in_array($thread->type, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE]) && $thread->ai_assistant && $thread->conversation
            && self::get($thread, Settings::language($thread->conversation->mailbox, $user));
        if ($thread->state == Thread::STATE_DRAFT
            || !(($thread->type == Thread::TYPE_CUSTOMER && self::isWanted($thread)) || ($thread->type == Thread::TYPE_MESSAGE && $thread->ai_assistant) || $requested)
        ) {
            return $result;
        }
        $result['wanted'] = true;
        $result['language'] = Settings::language($thread->conversation->mailbox, $user);
        $result['translation'] = self::get($thread, $result['language']);
        if ($thread->type == Thread::TYPE_CUSTOMER && self::isMissing($thread, $result['language'])) {
            \App\Jobs\AiTranslateThread::request($thread, $result['language']);
        }

        return $result;
    }

    public static function isWanted(Thread $thread)
    {
        return Settings::isConfigured()
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
            && !self::get($thread, Settings::language($thread->conversation->mailbox, $user));
    }

    /**
     * Translate a message now, on request, into the user's language: also a reply,
     * and also when an earlier translation took it to be in that language already.
     *
     * @return string|null the translation; null when it is in that language already
     */
    public static function forceTranslate(Thread $thread, $user)
    {
        $language = Settings::language($thread->conversation->mailbox, $user);
        $data = Summaries::data($thread);
        $data['same'] = array_values(array_diff((array) ($data['same'] ?? []), [$language]));
        if (!$data['same']) {
            unset($data['same']);
        }
        unset($data['no_text']);
        self::save($thread, $data);

        return self::translate($thread, $language);
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
        $data = Summaries::data($thread);
        $data['errors'][$language] = $limit;
        self::save($thread, $data);
    }

    /**
     * Keep why translating failed (it's tried again when someone reads it).
     */
    public static function failed(Thread $thread, $language, \Throwable $e)
    {
        $data = Summaries::data($thread);
        $data['errors'][$language] = mb_substr(trim($e->getMessage()) ?: get_class($e), 0, 300);
        self::save($thread, $data);
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
        $clean = function (\DOMNode $node) use (&$clean) {
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

    public static function translate(Thread $thread, $language)
    {
        $text = Summaries::text($thread);
        $data = Summaries::data($thread);
        unset($data['errors'][$language]);
        if (empty($data['errors'])) {
            unset($data['errors']);
        }
        if ($text !== '') {
            if (mb_strlen(trim((string) $thread->getBodyAsText())) > Summaries::MAX_THREAD_CHARS) {
                $data['truncated'] = true;
            }
            // As it looks (HTML) where that fits, else as text.
            $html = self::sourceHtml($thread);
            $as_html = $html !== '' && mb_strlen($html) <= Summaries::MAX_THREAD_CHARS * 3;
            $response = (new ThreadTranslator($language, $as_html))->prompt(trim(TallportAgent::glossary($thread->conversation->mailbox)."\n\n".TallportAgent::data('message', $as_html ? $html : $text)));
            Usage::record($response, Usage::FEATURE_TRANSLATION, $thread->conversation);
            $data['language'] = strtolower(trim((string) $response['detected_language'])) ?: ($data['language'] ?? null);
            // A chat's language: the one first detected (replies go out in it).
            if ($thread->type == Thread::TYPE_CUSTOMER && $data['language'] && Settings::isLanguage($data['language'])) {
                ChatTranslation::setCustomerLanguage($thread->conversation, $data['language']);
            }
            if ($response['same_language'] || $data['language'] === $language || trim((string) $response['translation']) === '') {
                $data['same'] = array_values(array_unique(array_merge((array) ($data['same'] ?? []), [$language])));
            } else {
                $data['translations'][$language] = trim((string) $response['translation']);
                $data['html'] = array_values(array_diff((array) ($data['html'] ?? []), [$language]));
                if ($as_html) {
                    $data['html'][] = $language;
                }
                if (!$data['html']) {
                    unset($data['html']);
                }
            }
        } else {
            $data['no_text'] = true;
        }
        self::save($thread, $data);

        return $data['translations'][$language] ?? null;
    }

    /**
     * A translation made with others (a chat's messages together, App\Ai\ChatTranslation):
     * plain text, or null when the message is in that language already.
     */
    public static function store(Thread $thread, $language, $detected, $translation)
    {
        $data = Summaries::data($thread);
        unset($data['errors'][$language]);
        if (empty($data['errors'])) {
            unset($data['errors']);
        }
        $data['language'] = $detected ?: ($data['language'] ?? null);
        $translation = trim((string) $translation);
        if ($translation === '' || $data['language'] === $language) {
            $data['same'] = array_values(array_unique(array_merge((array) ($data['same'] ?? []), [$language])));
        } else {
            $data['translations'][$language] = $translation;
            $data['html'] = array_values(array_diff((array) ($data['html'] ?? []), [$language]));
            if (!$data['html']) {
                unset($data['html']);
            }
        }
        self::save($thread, $data);
    }

    protected static function save(Thread $thread, array $data)
    {
        // Not touching updated_at: the thread didn't change.
        DB::table('threads')->where('id', $thread->id)->update([
            'ai_assistant'            => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ai_assistant_updated_at' => now(),
        ]);
        $thread->ai_assistant = json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
