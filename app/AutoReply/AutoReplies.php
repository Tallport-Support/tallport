<?php

namespace App\AutoReply;

use App\Ai\Agents\LanguageRecognizer;
use App\Ai\Agents\TallportAgent;
use App\Ai\Settings as AiSettings;
use App\Conversation;
use App\Mailbox;
use App\MailboxAutoReply;
use App\Thread;

/**
 * Auto replies in languages: a mailbox's auto reply (the default) and its
 * versions in other languages. A conversation gets the version in the
 * customer's language: Chinese, Japanese and Korean are recognised from the
 * writing system, other languages by the AI Assistant when it is set up.
 * A customer's language is remembered for a few hours.
 */
class AutoReplies
{
    const CJK = ['zh-Hans', 'zh-Hant', 'ja', 'ko'];

    /**
     * The most text of a message looked at.
     */
    const MAX_TEXT = 3000;

    /**
     * Hours a customer's language is remembered: people who send several
     * emails in a row get the same auto reply, recognised once.
     */
    const REMEMBER_HOURS = 4;

    /**
     * Chosen languages, by conversation ID: the subject and the HTML and
     * text bodies are made separately.
     */
    protected static $chosen = [];

    /**
     * A mailbox's versions in other languages, by language.
     */
    public static function versions(Mailbox $mailbox, $enabled_only = false)
    {
        $versions = MailboxAutoReply::where('mailbox_id', $mailbox->id);
        if ($enabled_only) {
            $versions->where('enabled', true);
        }

        return $versions->get()->keyBy('language');
    }

    /**
     * The auto reply for a conversation: subject, message and language (null
     * for the default auto reply).
     */
    public static function forConversation(Conversation $conversation, Mailbox $mailbox)
    {
        $language = self::language($conversation, $mailbox);
        $version = $language ? self::versions($mailbox, true)->get($language) : null;
        if (!$version) {
            return ['subject' => $mailbox->auto_reply_subject, 'message' => $mailbox->auto_reply_message, 'language' => null];
        }

        return ['subject' => $version->subject, 'message' => $version->message, 'language' => $language];
    }

    /**
     * The language of the version a conversation gets, or null.
     */
    public static function language(Conversation $conversation, Mailbox $mailbox)
    {
        if (array_key_exists($conversation->id, self::$chosen)) {
            return self::$chosen[$conversation->id];
        }
        $languages = self::versions($mailbox, true)->keys()->all();
        $language = null;
        if ($languages) {
            $key = 'auto_reply_language.'.$mailbox->id.'.'.$conversation->customer_id;
            $remembered = $conversation->customer_id ? \Cache::get($key) : null;
            if ($remembered !== null && ($remembered === '' || in_array($remembered, $languages))) {
                $language = $remembered ?: null;
            } else {
                [$language, $sure] = self::recognizeLanguage(self::text($conversation), $languages, $mailbox);
                if ($sure && $conversation->customer_id) {
                    // '': the default.
                    \Cache::put($key, (string) $language, now()->addHours(self::REMEMBER_HOURS));
                }
            }
        }

        if (count(self::$chosen) > 100) {
            self::$chosen = [];
        }

        return self::$chosen[$conversation->id] = $language;
    }

    /**
     * Which of the languages a text is in, or null.
     */
    public static function recognize($text, array $languages, ?Mailbox $mailbox = null)
    {
        return self::recognizeLanguage($text, $languages, $mailbox)[0];
    }

    /**
     * [language or null, whether that is sure]: not when the AI failed or
     * there is no text to go by.
     */
    protected static function recognizeLanguage($text, array $languages, ?Mailbox $mailbox = null)
    {
        $detector = new LanguageDetector();
        if (!preg_match('/\p{L}/u', $detector->prepareText($text))) {
            return [null, false];
        }
        $script = $detector->detect($text);
        if (in_array($script, [LanguageDetector::LANG_JAPANESE, LanguageDetector::LANG_KOREAN])) {
            return [in_array($script, $languages) ? $script : null, true];
        }
        if ($script == LanguageDetector::LANG_CHINESE) {
            // Else the other Chinese: closer than the default.
            $variant = $detector->chineseVariant($text) ?: LanguageDetector::LANG_CHINESE_SIMPLIFIED;
            $other = $variant == LanguageDetector::LANG_CHINESE_SIMPLIFIED ? LanguageDetector::LANG_CHINESE_TRADITIONAL : LanguageDetector::LANG_CHINESE_SIMPLIFIED;
            foreach ([$variant, $other] as $code) {
                if (in_array($code, $languages)) {
                    return [$code, true];
                }
            }

            return [null, true];
        }

        $others = array_values(array_diff($languages, self::CJK));
        if (!$others || !AiSettings::isConfigured()) {
            return [null, true];
        }
        try {
            $response = (new LanguageRecognizer($others))->prompt(TallportAgent::data('message', mb_substr($detector->prepareText($text), 0, self::MAX_TEXT)));
            \App\Ai\Usage::record($response, \App\Ai\Usage::FEATURE_LANGUAGE, null, $mailbox ? $mailbox->id : null);
            $code = (string) $response['language'];
        } catch (\Throwable $e) {
            \Helper::logException($e, '[Auto Reply] Language not recognised'.($mailbox ? ' (mailbox '.$mailbox->id.')' : '').':');

            return [null, false];
        }

        return [in_array($code, $others) ? $code : null, true];
    }

    /**
     * The language of a Telegram app (an IETF language tag, e.g. "pt-br",
     * "zh-hans"), if it is one of the languages.
     */
    public static function fromLanguageTag($tag, array $languages)
    {
        $tag = strtolower(str_replace('_', '-', trim((string) $tag)));
        if ($tag === '') {
            return null;
        }
        $base = explode('-', $tag)[0];
        $candidates = [$tag];
        switch ($base) {
            case 'zh':
                $candidates = in_array($tag, ['zh-hant', 'zh-tw', 'zh-hk', 'zh-mo']) ? ['zh-hant', 'zh-hans'] : ['zh-hans', 'zh-hant'];
                break;
            case 'pt':
                $candidates = $tag == 'pt-br' ? ['pt-br', 'pt-pt'] : ['pt-pt', 'pt-br'];
                break;
            case 'el':
                $candidates = ['el-monoton', 'el-polyton'];
                break;
            case 'ms':
                $candidates = ['ms-latn', 'ms-arab'];
                break;
            case 'nb':
            case 'nn':
                $candidates = ['no'];
                break;
            default:
                $candidates[] = $base;
        }
        foreach ($candidates as $candidate) {
            foreach ($languages as $language) {
                if (strtolower($language) == $candidate) {
                    return $language;
                }
            }
        }

        return null;
    }

    /**
     * What the customer wrote: the subject and their first message, without
     * quoted messages.
     */
    public static function text(Conversation $conversation)
    {
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->orderBy('id')->first();
        $text = trim((string) $conversation->subject);
        if ($thread) {
            $text .= "\n".self::threadText($thread);
        }

        return $text;
    }

    protected static function threadText(Thread $thread)
    {
        $body = mb_substr((string) $thread->body, 0, 200000);
        if (trim($body) === '') {
            return '';
        }
        // Quoted messages cut off as for replies.
        try {
            $separated = (new \App\Console\Commands\FetchEmails())->separateReply($body, true, true);
            if (is_string($separated) && trim(strip_tags($separated)) !== '') {
                $body = $separated;
            }
        } catch (\Throwable $e) {
            // The whole body.
        }
        $stripped = preg_replace('~<blockquote\b.*</blockquote>~isu', ' ', $body);
        if (is_string($stripped) && trim(strip_tags($stripped)) !== '') {
            $body = $stripped;
        }

        return (string) \Helper::htmlToText($body);
    }

    public static function forget()
    {
        self::$chosen = [];
    }
}
