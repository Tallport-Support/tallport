<?php

namespace App\Jobs;

use App\Ai\Summaries;
use App\Ai\Translations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Customers' messages not translated because the AI was unavailable, tried again (every few
 * minutes, app/Console/Kernel): the latest first, and once it works the others, so that an
 * outage of the provider doesn't leave them untranslated until someone opens each.
 */
class AiRetryTranslations implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 240;

    public $uniqueFor = 600;

    public function __construct()
    {
        $this->onConnection(\Helper::queueConnection('ai'));
        $this->onQueue('ai');
    }

    public function handle()
    {
        $failed = Translations::failedRecently();
        if (!$failed) {
            return;
        }
        [$thread, $language] = array_shift($failed);
        Translations::retrying($thread, $language);
        (new AiTranslateThread($thread->id, $language))->handle();
        // Still unavailable, or over a limit: the others wait for the next time.
        $data = Summaries::data($thread->fresh());
        if (isset($data['errors'][$language]) && !in_array($language, (array) ($data['bad_answer'] ?? []))) {
            return;
        }
        foreach ($failed as [$thread, $language]) {
            Translations::retrying($thread, $language);
            AiTranslateThread::request($thread, $language);
        }
    }
}
