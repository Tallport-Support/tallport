<?php

namespace App\Jobs;

use App\Ai\Settings;
use App\Ai\Summaries;
use App\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Make or update a conversation's AI summary in a language.
 */
class AiSummarizeConversation implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $conversation_id;

    public $language;

    public $tries = 1;

    public $timeout = 240;

    public $uniqueFor = 600;

    public function __construct($conversation_id, $language)
    {
        $this->onConnection(\Helper::queueConnection('ai'));
        $this->onQueue('ai');
        $this->conversation_id = $conversation_id;
        $this->language = $language;
    }

    public function uniqueId()
    {
        return $this->conversation_id.'-'.$this->language;
    }

    public function handle()
    {
        $conversation = Conversation::find($this->conversation_id);
        if (!$conversation || !Summaries::isWanted($conversation) || !Summaries::isStale($conversation, $this->language)
            || !\App\Ai\Settings::withinBudget($conversation->mailbox)
        ) {
            return;
        }

        try {
            Summaries::summarize($conversation, $this->language);
        } catch (\Throwable $e) {
            \App\Ai\Errors::report($e, 'Summary of conversation #'.$conversation->number.':');

            return;
        }
        // Open pages show the new summary.
        $last_thread = $conversation->threads()->where('state', \App\Thread::STATE_PUBLISHED)->orderBy('id', 'desc')->first();
        if ($last_thread) {
            \App\Events\RealtimeConvNewThread::dispatchSelf($last_thread, ['ai_updated' => true]);
        }
    }

    /**
     * Queue a summary, a minute later so that it covers quick follow-ups.
     */
    public static function request(Conversation $conversation, $language)
    {
        // handle() checks the rest: the conversation of an event may not
        // have its new thread counted yet.
        if (Settings::isConfigured() && Settings::enabled('summaries', $conversation->mailbox)) {
            self::dispatch($conversation->id, $language)->delay(now()->addMinute());
        }
    }
}
