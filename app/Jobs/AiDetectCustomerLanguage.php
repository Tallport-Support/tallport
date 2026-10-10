<?php

namespace App\Jobs;

use App\Ai\ChatTranslation;
use App\Ai\Settings;
use App\Ai\Translations;
use App\AutoReply\AutoReplies;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A customer without a language: recognised in their message (Chinese, Japanese and Korean
 * by the writing system, others by the AI) and kept on their profile. A message translated
 * anyway gives it with its translation (App\Ai\Translations) instead.
 */
class AiDetectCustomerLanguage implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $thread_id;

    public $tries = 1;

    public $timeout = 120;

    public $uniqueFor = 600;

    public function __construct($thread_id)
    {
        $this->onConnection(\Helper::queueConnection('ai'));
        $this->onQueue('ai');
        $this->thread_id = $thread_id;
    }

    public function uniqueId()
    {
        return $this->thread_id;
    }

    public function handle()
    {
        $thread = Thread::find($this->thread_id);
        if (!$thread || !self::wanted($thread) || !Settings::withinBudget($thread->conversation->mailbox)) {
            return;
        }
        // The customer's own words: the subject and their first message in the conversation.
        $language = AutoReplies::recognize(AutoReplies::text($thread->conversation), array_keys(Settings::LANGUAGES), $thread->conversation->mailbox);
        ChatTranslation::setCustomerLanguage($thread->customer, $language);
    }

    /**
     * A customer's message: their language recognised, when they have none and the message
     * isn't translated anyway (not a bounce: one is known by the time this runs).
     */
    public static function request(Thread $thread)
    {
        if (self::wanted($thread) && !Translations::isWanted($thread)) {
            self::dispatch($thread->id);
        }
    }

    protected static function wanted(Thread $thread)
    {
        return Settings::isConfigured()
            && !$thread->getMeta('chat_pending')
            && $thread->type == Thread::TYPE_CUSTOMER
            && $thread->state == Thread::STATE_PUBLISHED
            && $thread->conversation
            && $thread->customer
            && !$thread->customer->language
            && !$thread->isBounce();
    }
}
