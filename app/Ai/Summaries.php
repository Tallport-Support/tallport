<?php

namespace App\Ai;

use App\Ai\Agents\ConversationSummarizer;
use App\Conversation;
use App\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Conversation summaries, by language, in conversations.ai_assistant:
 * {"summaries": {"en": {"one_liner", "background", "memory", "thread_id", "at"}}}: where the
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
     * The maximum messages in each summary update.
     */
    const MAX_THREADS = ConversationContext::MAX_MESSAGES;

    const MAX_THREAD_CHARS = ConversationContext::MAX_MESSAGE_CHARS;

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
        return \Cache::lock('ai-summary:'.$conversation->id.':'.$language, 180)->get(function () use ($conversation, $language) {
            if (!ConversationContext::threads($conversation)->exists()) {
                return null;
            }
            $deadline = microtime(true) + 120;
            $context = ConversationContext::build($conversation, $language, true, null, $deadline);
            $with_background = ConversationContext::threads($conversation)->count() >= self::BACKGROUND_MIN_MESSAGES;
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 1) {
                throw new \RuntimeException(__('The AI\'s answer could not be read.'));
            }
            $response = (new ConversationSummarizer($language, $with_background))->recordFor(Usage::FEATURE_SUMMARY, $conversation)
                ->prompt($context['prompt'], timeout: $remaining);
            if (!is_string($response['one_liner'] ?? null) || trim($response['one_liner']) === ''
                || !is_string($response['memory'] ?? null) || trim($response['memory']) === ''
            ) {
                throw new \RuntimeException(__('The AI\'s answer could not be read.'));
            }
            $summary = [
                'one_liner' => mb_substr(trim((string) $response['one_liner']), 0, 500),
                'background' => $with_background ? mb_substr(trim((string) ($response['background'] ?? '')), 0, 2000) : '',
                'memory' => mb_substr(trim($response['memory']), 0, ConversationContext::MAX_MEMORY_CHARS),
                'thread_id' => $context['last_thread_id'],
                'at' => now()->toDateTimeString(),
            ];
            self::updateData($conversation, function ($data) use ($language, $summary) {
                // Never back to older messages, but a summary from before backgrounds is replaced.
                $saved = $data['summaries'][$language] ?? null;
                if ($saved && array_key_exists('background', $saved) && (int) ($saved['thread_id'] ?? 0) > $summary['thread_id']) {
                    return null;
                }
                $data['summaries'][$language] = $summary;

                return $data;
            });

            return self::get($conversation, $language);
        });
    }

    /**
     * The conversation's messages and notes, newest first.
     */
    protected static function threads(Conversation $conversation)
    {
        return ConversationContext::threads($conversation)->orderBy('id', 'desc')->limit(self::MAX_THREADS);
    }

    public static function text(Thread $thread)
    {
        return ConversationContext::text($thread);
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
            // A summary or translation: the conversation's pane changed (App\Inbox\Changes).
            \App\Inbox\Changes::conversations([(int) ($model->conversation_id ?? $model->id)]);

            return $data;
        });
    }
}
