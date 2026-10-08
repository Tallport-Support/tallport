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
        $response = (new ConversationSummarizer($language, $with_background))->recordFor(Usage::FEATURE_SUMMARY, $conversation)->prompt(TallportAgent::data('conversation', [
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

        $summary = [
            'one_liner'  => trim((string) $response['one_liner']),
            'background' => $with_background ? trim((string) ($response['background'] ?? '')) : '',
            'thread_id' => (int) $threads->last()->id,
            'at'        => now()->toDateTimeString(),
        ];
        self::updateData($conversation, function ($data) use ($language, $summary) {
            $data['summaries'][$language] = $summary;

            return $data;
        });

        return $summary;
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

    /**
     * Merge one AI change into the current row, after any external request has finished.
     * A null result leaves the row alone, but refreshes the caller's model.
     */
    public static function updateData($model, callable $change, $touch_timestamp = true)
    {
        return DB::transaction(function () use ($model, $change, $touch_timestamp) {
            $query = DB::table($model->getTable())->where('id', $model->id);
            $row = (clone $query)->lockForUpdate()->first(['ai_assistant']);
            if (!$row) {
                return null;
            }
            $model->ai_assistant = $row->ai_assistant;
            $data = $change(self::data($model));
            if ($data === null) {
                return null;
            }

            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            $updates = ['ai_assistant' => $json];
            if ($touch_timestamp) {
                $updates['ai_assistant_updated_at'] = now();
            }
            $query->update($updates);
            $model->ai_assistant = $json;

            return $data;
        });
    }
}
