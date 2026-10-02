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
            && ($data['language'] ?? null) !== $language;
    }

    public static function translate(Thread $thread, $language)
    {
        $text = Summaries::text($thread);
        $data = Summaries::data($thread);
        if ($text !== '') {
            $response = (new ThreadTranslator($language))->prompt(TallportAgent::data('message', $text));
            $data['language'] = strtolower(trim((string) $response['detected_language'])) ?: ($data['language'] ?? null);
            if ($response['same_language'] || trim((string) $response['translation']) === '') {
                $data['same'] = array_values(array_unique(array_merge((array) ($data['same'] ?? []), [$language])));
            } else {
                $data['translations'][$language] = trim((string) $response['translation']);
            }
        } else {
            $data['same'] = array_values(array_unique(array_merge((array) ($data['same'] ?? []), [$language])));
        }

        // Not touching updated_at: the thread didn't change.
        DB::table('threads')->where('id', $thread->id)->update([
            'ai_assistant'            => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ai_assistant_updated_at' => now(),
        ]);
        $thread->ai_assistant = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $data['translations'][$language] ?? null;
    }
}
