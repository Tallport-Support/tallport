<?php

namespace App\Jobs;

use App\Ai\ChatTranslation;
use App\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A chat's customer messages translated together (App\Ai\ChatTranslation::translateIncoming()):
 * a few seconds after the latest, so that a burst is one AI call. Messages arriving while it
 * waits join it; one arriving while it runs makes the next.
 */
class AiTranslateChat implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 240;

    public $uniqueFor = 600;

    public $conversation_id;

    public $language;

    public function __construct($conversation_id, $language)
    {
        $this->conversation_id = $conversation_id;
        $this->language = $language;
        $this->onConnection(\Helper::queueConnection('ai'));
        $this->onQueue('ai');
    }

    public function uniqueId()
    {
        return $this->conversation_id.'-'.$this->language;
    }

    public function handle()
    {
        $conversation = Conversation::find($this->conversation_id);
        if (!$conversation) {
            return;
        }
        $threads = ChatTranslation::translateIncoming($conversation, $this->language);
        // Open pages show them (or why not) in place of "Translating…".
        if ($threads) {
            \App\Events\RealtimeConvNewThread::dispatchSelf(collect($threads)->sortBy('id')->last(), ['ai_updated' => true]);
        }
    }
}
