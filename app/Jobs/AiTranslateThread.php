<?php

namespace App\Jobs;

use App\Ai\Translations;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Translate a customer's message into a language.
 */
class AiTranslateThread implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $thread_id;

    public $language;

    public $tries = 1;

    public $timeout = 240;

    public $uniqueFor = 600;

    public function __construct($thread_id, $language)
    {
        $this->onQueue('ai');
        $this->thread_id = $thread_id;
        $this->language = $language;
    }

    public function uniqueId()
    {
        return $this->thread_id.'-'.$this->language;
    }

    public function handle()
    {
        $thread = Thread::find($this->thread_id);
        if (!$thread || !Translations::isWanted($thread) || !Translations::isMissing($thread, $this->language)) {
            return;
        }

        if ($limit = Translations::limit($thread)) {
            Translations::limited($thread, $this->language, $limit);
        } else {
            try {
                Translations::translate($thread, $this->language);
            } catch (\Throwable $e) {
                \Helper::logException($e, '[AI] Translation of thread '.$thread->id.':');
                Translations::failed($thread, $this->language, $e);
            }
        }
        // Open pages show it (or why not) in place of "Translating…".
        \App\Events\RealtimeConvNewThread::dispatchSelf($thread, ['ai_updated' => true]);
    }

    public static function request(Thread $thread, $language)
    {
        if (!Translations::isWanted($thread) || !Translations::isMissing($thread, $language)) {
            return;
        }
        // A translated chat's messages: together, a few seconds after the latest.
        if (\App\Ai\ChatTranslation::isOn($thread->conversation)) {
            AiTranslateChat::dispatch($thread->conversation_id, $language)->delay(now()->addSeconds(\App\Ai\ChatTranslation::BATCH_DELAY));

            return;
        }
        self::dispatch($thread->id, $language);
    }
}
