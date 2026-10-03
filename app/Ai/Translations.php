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

    public static function isWanted(Thread $thread)
    {
        return Settings::isConfigured()
            && $thread->type == Thread::TYPE_CUSTOMER
            && $thread->state == Thread::STATE_PUBLISHED
            && $thread->conversation
            && Settings::enabled('translations', $thread->conversation->mailbox);
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
     * ['no_text'], ['error', message], ['waiting'], or null (nothing to say:
     * it is in that language).
     */
    public static function reason(Thread $thread, $language)
    {
        $data = Summaries::data($thread);
        if (!empty($data['no_text'])) {
            return ['no_text'];
        }
        if (isset($data['errors'][$language])) {
            return ['error', $data['errors'][$language]];
        }
        if (!self::isMissing($thread, $language)) {
            $detected = $data['language'] ?? null;

            return $detected && $detected !== $language ? ['same', $detected] : null;
        }

        return ['waiting'];
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
            $response = (new ThreadTranslator($language))->prompt(TallportAgent::data('message', $text));
            $data['language'] = strtolower(trim((string) $response['detected_language'])) ?: ($data['language'] ?? null);
            if ($response['same_language'] || trim((string) $response['translation']) === '') {
                $data['same'] = array_values(array_unique(array_merge((array) ($data['same'] ?? []), [$language])));
            } else {
                $data['translations'][$language] = trim((string) $response['translation']);
            }
        } else {
            $data['no_text'] = true;
        }
        self::save($thread, $data);

        return $data['translations'][$language] ?? null;
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
