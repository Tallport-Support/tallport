<?php

namespace App\Ai;

use App\Ai\Agents\ReplyDrafter;
use App\Ai\Agents\TallportAgent;
use App\Conversation;
use App\Thread;

/**
 * Reply drafts: in the customer's language, from the conversation (without
 * internal notes), the mailbox's documentation and customer context.
 */
class Drafts
{
    /**
     * The newest messages a draft is made from.
     */
    const MAX_THREADS = 12;

    const MAX_THREAD_CHARS = 3000;

    const MAX_DOCUMENT_CHARS = 1800;

    /**
     * Whether a user can draft replies in a conversation (now).
     */
    public static function allowed($user, Conversation $conversation)
    {
        return $user
            && Settings::isConfigured()
            && Settings::enabled('drafts', $conversation->mailbox)
            && Settings::draftsPerDay($user) > 0
            && $user->can('view', $conversation);
    }

    public static function limitReached($user)
    {
        return DraftJob::countToday($user) >= Settings::draftsPerDay($user);
    }

    /**
     * Make a draft. $language: the language the user reads its translation in.
     */
    public static function draft(Conversation $conversation, $language)
    {
        $threads = $conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('id', 'desc')
            ->limit(self::MAX_THREADS)
            ->get()
            ->reverse()
            ->values();
        $latest = $threads->where('type', Thread::TYPE_CUSTOMER)->last();
        $latest_text = $latest ? self::text($latest) : '';
        $locale = self::documentationLocale($latest, $latest_text);

        $documentation = self::documentation($conversation, trim($conversation->subject."\n".$latest_text), $locale);
        $context = CustomerContext::forConversation($conversation);

        $response = (new ReplyDrafter($language))->prompt(implode("\n\n", [
            TallportAgent::data('conversation', [
                'subject'  => (string) $conversation->subject,
                'customer' => ['name' => $conversation->customer ? $conversation->customer->getFullName(true, true) : '', 'email' => $conversation->customer_email],
                'messages' => $threads->map(function (Thread $thread) {
                    return [
                        'author' => $thread->getCreatedBy()->getFullName(),
                        'type'   => $thread->type == Thread::TYPE_CUSTOMER ? 'customer' : 'staff_reply',
                        'date'   => (string) $thread->created_at,
                        'body'   => self::text($thread),
                    ];
                })->all(),
            ]),
            TallportAgent::data('mailbox_guidance', $context['guidance']),
            TallportAgent::data('documentation', $documentation['chunks']),
            TallportAgent::data('customer_context', $context['data']),
        ]));

        return [
            'draft'                   => trim((string) $response['draft']),
            'translation'             => trim((string) $response['translation']),
            'translation_language'    => $language,
            'language'                => (string) $response['language'],
            'confidence'              => in_array($response['confidence'], ['low', 'medium', 'high']) ? $response['confidence'] : 'low',
            'documentation_urls'      => array_values(array_filter((array) $response['documentation_urls'], [Document::class, 'isHttpUrl'])),
            'staff_notes'             => array_values(array_map('strval', (array) $response['staff_notes'])),
            'retrieved_documents'     => $documentation['chunks'],
            'documentation_status'    => $documentation['status'],
            'customer_context_status' => $context['status'],
        ];
    }

    /**
     * The mailbox's documentation most like the question: [status, chunks].
     */
    protected static function documentation(Conversation $conversation, $question, $locale)
    {
        if (!Documents::available()) {
            return ['status' => 'disabled', 'chunks' => []];
        }
        try {
            $results = Documents::search($conversation->mailbox_id, $question, $locale);
        } catch (\Throwable $e) {
            return ['status' => 'failed: '.$e->getMessage(), 'chunks' => []];
        }

        return [
            'status' => $results ? 'available' : 'no_matches',
            'chunks' => array_map(function ($result) {
                return [
                    'title'   => $result['title'],
                    'url'     => $result['url'],
                    'score'   => round($result['score'], 4),
                    'content' => mb_substr($result['content'], 0, self::MAX_DOCUMENT_CHARS),
                ];
            }, $results),
        ];
    }

    /**
     * The documentation locale for the customer: from the language detected
     * when their message was translated, else from its script.
     */
    protected static function documentationLocale($thread, $text)
    {
        $language = $thread ? (string) (Summaries::data($thread)['language'] ?? '') : '';
        if (in_array($language, Document::SUPPORTED_LOCALES)) {
            return $language;
        }
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

    protected static function text(Thread $thread)
    {
        return mb_substr(Summaries::text($thread), 0, self::MAX_THREAD_CHARS);
    }

    /**
     * A reply made from a draft keeps the draft's translation, shown with it.
     */
    public static function keepTranslation(Thread $thread, $request)
    {
        $translation = trim(strip_tags((string) $request->input('ai_draft_translation', '')));
        $language = (string) $request->input('ai_draft_translation_language', '');
        if ($thread->type != Thread::TYPE_MESSAGE || $translation === '' || !Settings::isLanguage($language)) {
            return;
        }
        $data = Summaries::data($thread);
        $data['translations'][$language] = mb_substr($translation, 0, 20000);
        $thread->ai_assistant = json_encode($data, JSON_UNESCAPED_UNICODE);
        $thread->ai_assistant_updated_at = now();
    }
}
