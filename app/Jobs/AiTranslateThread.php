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

        try {
            Translations::translate($thread, $this->language);
        } catch (\Throwable $e) {
            \Helper::logException($e, '[AI Assistant] Translation of thread '.$thread->id.':');
        }
    }

    public static function request(Thread $thread, $language)
    {
        if (Translations::isWanted($thread) && Translations::isMissing($thread, $language)) {
            self::dispatch($thread->id, $language);
        }
    }
}
