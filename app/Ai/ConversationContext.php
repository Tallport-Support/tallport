<?php

namespace App\Ai;

use App\Ai\Agents\TallportAgent;
use App\Conversation;
use App\Thread;

/**
 * The same bounded context for summaries, drafts and translations. Summary updates read
 * forward from their saved position; other tasks read the most recent messages.
 */
class ConversationContext
{
    const MAX_MESSAGES = 20;
    const MAX_MESSAGE_CHARS = 4000;
    const MAX_HISTORY_CHARS = 24000;
    const MAX_MEMORY_CHARS = 6000;
    const MAX_DOCUMENT_CHARS = 6000;
    const MAX_DOCUMENTATION_CHARS = 12000;

    public static function build(Conversation $conversation, $language, $incremental = false, $before_id = null, ?float $deadline = null, ?Thread $target = null)
    {
        $deadline = $deadline ?? microtime(true) + 120;
        $summary = self::summary($conversation, $language, $before_id);
        $history = self::history($conversation, $incremental ? (int) ($summary['thread_id'] ?? 0) : null, $before_id);
        $latest = $target ?? $history['customer'];
        $question = trim(mb_substr((string) $conversation->subject, 0, 1000)."\n".($latest ? self::text($latest) : ''));
        $documentation = self::documentation($conversation, $question, self::documentationLocale($latest), $deadline);
        $customer_context = CustomerContext::forConversation($conversation, $deadline);
        $data = [
            'subject' => mb_substr((string) $conversation->subject, 0, 1000),
            'customer' => [
                'name' => mb_substr($conversation->customer ? $conversation->customer->getFullName(true, true) : '', 0, 200),
                'email' => mb_substr((string) $conversation->customer_email, 0, 500),
            ],
            'previous_summary' => $summary,
            'messages' => $history['messages'],
            'history_incomplete' => $history['incomplete'],
        ];

        return [
            'conversation' => $data,
            'last_thread_id' => $history['last_thread_id'] ?: (int) ($summary['thread_id'] ?? 0),
            'documentation' => $documentation,
            'customer_context' => $customer_context,
            'prompt' => implode("\n\n", array_filter([
                TallportAgent::data('conversation', $data),
                Settings::glossary($conversation->mailbox) === '' ? '' : TallportAgent::data('glossary', mb_substr(Settings::glossary($conversation->mailbox), 0, 6000)),
                TallportAgent::data('mailbox_guidance', $customer_context['guidance']),
                TallportAgent::data('documentation', $documentation['chunks']),
                TallportAgent::data('documentation_status', $documentation['status']),
                TallportAgent::data('customer_context', $customer_context['data']),
            ])),
        ];
    }

    public static function threads(Conversation $conversation)
    {
        return $conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE])
            ->where('state', Thread::STATE_PUBLISHED);
    }

    /**
     * No query loads more than one batch. A partial batch advances only through messages
     * actually included, so the next summary update cannot skip an unseen message.
     */
    public static function history(Conversation $conversation, $after_id = null, $before_id = null)
    {
        $query = self::threads($conversation)
            ->when($after_id !== null, fn ($query) => $query->where('id', '>', $after_id))
            ->when($before_id !== null, fn ($query) => $query->where('id', '<', $before_id));
        $threads = (clone $query)->with(['created_by_user', 'created_by_customer'])
            ->orderBy('id', $after_id !== null ? 'asc' : 'desc')->limit(self::MAX_MESSAGES)->get();
        $messages = [];
        $chars = 0;
        $customer = null;
        $truncated = false;
        foreach ($threads as $thread) {
            $body = trim(preg_replace("/\n{3,}/", "\n\n", (string) $thread->getBodyAsText()));
            $text = mb_substr($body, 0, self::MAX_MESSAGE_CHARS);
            if ($chars + mb_strlen($text) > self::MAX_HISTORY_CHARS) {
                break;
            }
            $chars += mb_strlen($text);
            $truncated = $truncated || mb_strlen($body) > mb_strlen($text);
            $messages[] = [
                'id' => (int) $thread->id,
                'author' => mb_substr($thread->getCreatedBy()->getFullName(), 0, 200),
                'type' => $thread->type == Thread::TYPE_CUSTOMER ? 'customer_to_staff'
                    : ($thread->type == Thread::TYPE_NOTE ? 'internal_note' : 'staff_to_customer'),
                'date' => (string) $thread->created_at,
                'body' => $text,
                'truncated' => mb_strlen($body) > mb_strlen($text),
            ];
            if ($thread->type == Thread::TYPE_CUSTOMER && (!$customer || $thread->id > $customer->id)) {
                $customer = $thread;
            }
        }
        if ($after_id === null) {
            $messages = array_reverse($messages);
        }
        $first_id = $messages[0]['id'] ?? 0;
        $last_id = $messages ? $messages[count($messages) - 1]['id'] : 0;
        $omitted = $messages && (clone $query)->where('id', $after_id !== null ? '>' : '<', $after_id !== null ? $last_id : $first_id)->exists();

        return ['messages' => $messages, 'last_thread_id' => $last_id, 'customer' => $customer, 'incomplete' => $truncated || $omitted];
    }

    /**
     * The freshest saved summary, in any language. Historical translations never receive a
     * summary that includes messages at or after the message being translated.
     */
    protected static function summary(Conversation $conversation, $language, $before_id)
    {
        $data = json_decode((string) Conversation::whereKey($conversation->id)->value('ai_assistant'), true);
        $best = null;
        foreach ((array) ($data['summaries'] ?? []) as $locale => $summary) {
            $id = (int) ($summary['thread_id'] ?? 0);
            if (!$id || ($before_id !== null && $id >= $before_id) || !array_key_exists('background', $summary)) {
                continue;
            }
            if ($best && ($id < $best['thread_id'] || ($id == $best['thread_id'] && $locale !== $language))) {
                continue;
            }
            $best = [
                'language' => $locale,
                'thread_id' => $id,
                'one_liner' => mb_substr((string) ($summary['one_liner'] ?? ''), 0, 500),
                'background' => mb_substr((string) $summary['background'], 0, 2000),
                'memory' => mb_substr((string) ($summary['memory'] ?? ''), 0, self::MAX_MEMORY_CHARS),
            ];
        }

        return $best;
    }

    public static function text(Thread $thread)
    {
        return mb_substr(trim(preg_replace("/\n{3,}/", "\n\n", (string) $thread->getBodyAsText())), 0, self::MAX_MESSAGE_CHARS);
    }

    protected static function documentation(Conversation $conversation, $question, $locale, $deadline)
    {
        $results = Documents::searchKnowledgeBase($conversation->mailbox_id, $question, Settings::retrievalLimit());
        $status = Documents::available() ? 'no_matches' : 'disabled';
        if (Documents::available() && Document::where('mailbox_id', $conversation->mailbox_id)->where('enabled', true)->where('status', Document::STATUS_INDEXED)->exists()) {
            try {
                $semantic = Documents::search($conversation->mailbox_id, $question, $locale, null, $deadline);
                $combined = [];
                for ($i = 0; $i < max(count($results), count($semantic)); $i++) {
                    foreach ([$results[$i] ?? null, $semantic[$i] ?? null] as $result) {
                        if ($result) {
                            $combined[] = $result;
                        }
                    }
                }
                $seen = [];
                $results = array_values(array_filter($combined, function ($result) use (&$seen) {
                    foreach ($seen[$result['document_id']] ?? [] as $content) {
                        if (str_contains($content, $result['content'])) {
                            return false;
                        }
                    }
                    $seen[$result['document_id']][] = $result['content'];

                    return true;
                }));
            } catch (\Throwable $e) {
                $status = 'failed: '.Errors::message($e, 'Documentation search for conversation '.$conversation->id.':');
            }
        }

        $chunks = [];
        $remaining = self::MAX_DOCUMENTATION_CHARS;
        foreach (array_slice($results, 0, Settings::retrievalLimit()) as $result) {
            if ($remaining < 500) {
                break;
            }
            $content = Documents::excerpt($result['content'], $question, min(self::MAX_DOCUMENT_CHARS, $remaining));
            $remaining -= mb_strlen($content);
            $chunks[] = [
                'title' => mb_substr($result['title'], 0, 200),
                'url' => mb_substr($result['url'], 0, 2000),
                'score' => round($result['score'], 4),
                'content' => $content,
            ];
        }

        return ['status' => $chunks ? 'available' : $status, 'chunks' => $chunks];
    }

    protected static function documentationLocale($thread)
    {
        $language = $thread ? (string) (Summaries::data($thread)['language'] ?? '') : '';
        if (in_array($language, Document::SUPPORTED_LOCALES)) {
            return $language;
        }
        $text = $thread ? self::text($thread) : '';
        if (preg_match('/[\x{1100}-\x{11ff}\x{3130}-\x{318f}\x{ac00}-\x{d7af}]/u', $text)) {
            return 'ko';
        }
        if (preg_match('/[\x{3040}-\x{30ff}]/u', $text)) {
            return 'ja';
        }
        if (preg_match('/\p{Han}/u', $text)) {
            return 'zh';
        }

        return Document::CANONICAL_LOCALE;
    }
}
