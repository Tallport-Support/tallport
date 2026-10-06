<?php

namespace App\Ai;

use App\Ai\Agents\ConversationSummarizer;
use App\Ai\Agents\TallportAgent;
use App\Conversation;
use App\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Conversation summaries, by language, in conversations.ai_assistant:
 * {"summaries": {"en": {"one_liner", "background", "thread_id", "at"}}}: where the
 * conversation stands, and for long ones what's been tried and what's open.
 * thread_id is the newest thread summarized: a newer one makes it stale.
 */
class Summaries
{
    /**
     * From this many messages and notes a summary has a background.
     */
    const BACKGROUND_MIN_MESSAGES = 10;

    /**
     * The newest threads a summary is made of.
     */
    const MAX_THREADS = 30;

    const MAX_THREAD_CHARS = 4000;

    public static function get(Conversation $conversation, $language)
    {
        $data = self::data($conversation);

        return $data['summaries'][$language] ?? null;
    }

    /**
     * For the conversation list: in the language asked for, else any.
     */
    public static function getAny(Conversation $conversation, $language)
    {
        $summaries = (array) (self::data($conversation)['summaries'] ?? []);

        return $summaries[$language] ?? (reset($summaries) ?: null);
    }

    public static function isWanted(Conversation $conversation)
    {
        return Settings::isConfigured()
            && Settings::enabled('summaries', $conversation->mailbox)
            && $conversation->threads_count > Settings::summaryThreshold()
            && !$conversation->isSpam()
            && $conversation->state == Conversation::STATE_PUBLISHED;
    }

    public static function isStale(Conversation $conversation, $language)
    {
        $summary = self::get($conversation, $language);

        // Made before backgrounds (a chronological "summary" instead): made again once.
        return !$summary || !array_key_exists('background', $summary)
            || (int) ($summary['thread_id'] ?? 0) < (int) self::threads($conversation)->max('id');
    }

    /**
     * Make (or remake) the summary in a language.
     */
    public static function summarize(Conversation $conversation, $language)
    {
        $threads = self::threads($conversation)->get()->reverse()->values();
        if (!count($threads)) {
            return null;
        }

        $with_background = self::threads($conversation)->limit(null)->count() >= self::BACKGROUND_MIN_MESSAGES;
        $response = (new ConversationSummarizer($language, $with_background))->prompt(TallportAgent::data('conversation', [
            'subject'  => (string) $conversation->subject,
            'messages' => $threads->map(function (Thread $thread) {
                return [
                    'author' => $thread->getCreatedBy()->getFullName(),
                    'type'   => $thread->type == Thread::TYPE_CUSTOMER ? 'customer_to_staff'
                        : ($thread->type == Thread::TYPE_NOTE ? 'internal_note' : 'staff_to_customer'),
                    'date'   => (string) $thread->created_at,
                    'body'   => self::text($thread),
                ];
            })->all(),
        ]));

        Usage::record($response, Usage::FEATURE_SUMMARY, $conversation);

        $data = self::data($conversation);
        $data['summaries'][$language] = [
            'one_liner'  => trim((string) $response['one_liner']),
            'background' => $with_background ? trim((string) ($response['background'] ?? '')) : '',
            'thread_id' => (int) $threads->last()->id,
            'at'        => now()->toDateTimeString(),
        ];
        // Not touching updated_at: the conversation didn't change.
        DB::table('conversations')->where('id', $conversation->id)->update([
            'ai_assistant'            => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ai_assistant_updated_at' => now(),
        ]);
        $conversation->ai_assistant = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $data['summaries'][$language];
    }

    /**
     * The conversation's messages and notes, newest first.
     */
    protected static function threads(Conversation $conversation)
    {
        return $conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('id', 'desc')
            ->limit(self::MAX_THREADS);
    }

    public static function text(Thread $thread)
    {
        $text = trim(preg_replace("/\n{3,}/", "\n\n", (string) $thread->getBodyAsText()));

        return mb_substr($text, 0, self::MAX_THREAD_CHARS);
    }

    public static function data($model)
    {
        $data = json_decode((string) $model->ai_assistant, true);

        return is_array($data) ? $data : [];
    }
}
