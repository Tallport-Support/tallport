<?php

namespace App\Events;

use App\Conversation;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Customers' messages being translated (App\Ai\Translations, App\Ai\ChatTranslation): the
 * translations so far, shown in the open conversation as they're written (public/js/realtime.js).
 * RealtimeConvNewThread's ai_updated follows when they're done.
 */
class RealtimeConvTranslating implements ShouldBroadcastNow
{
    /**
     * conversation_id, language, at (when, to show the latest), translations: thread_id, text, html (whether it's HTML).
     */
    public $data = [];

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function broadcastOn()
    {
        return new \Illuminate\Broadcasting\Channel('conv.'.$this->data['conversation_id']);
    }

    public function broadcastWith()
    {
        return $this->data;
    }

    /**
     * $translations: thread_id => [text, html]. Never stops the translation.
     */
    public static function dispatchSelf(Conversation $conversation, $language, array $translations)
    {
        $data = [
            'conversation_id' => $conversation->id,
            'language'        => $language,
            'at'              => microtime(true),
            'translations'    => [],
        ];
        foreach ($translations as $thread_id => [$text, $html]) {
            $data['translations'][] = ['thread_id' => $thread_id, 'text' => (string) $text, 'html' => (bool) $html];
        }
        try {
            event(new self($data));
        } catch (\Throwable $e) {
            \App\Ai\Errors::report($e, 'Translation of conversation '.$conversation->id.' (as it is written):');
        }
    }

    /**
     * For users who can see the conversation and read it in that language: the translations made
     * safe to show, as the message's translation is (conversations/partials/ai_translation).
     */
    public static function processPayload($payload)
    {
        $user = auth()->user();
        $conversation = Conversation::find($payload->conversation_id ?? null);
        if (!$user || !$conversation || !$user->can('view', $conversation)
            || \App\Ai\Settings::language($conversation->mailbox, $user) !== ($payload->language ?? null)
        ) {
            return [];
        }
        foreach ((array) ($payload->translations ?? []) as $translation) {
            $text = (string) ($translation->text ?? '');
            // A tag still being written is left out.
            $translation->content = !empty($translation->html)
                ? '<div class="ai-translation-html">'.safe_raw_html(preg_replace('/<[^>]*$/', '', $text)).'</div>'
                : nl2br(e($text));
            unset($translation->text, $translation->html);
        }

        return $payload;
    }
}
