<?php

namespace App\Jobs;

use App\Nostr\Announcer;
use App\Nostr\Nostr;
use App\Nostr\NostrMailbox;
use App\Nostr\OutgoingMessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Nostr work done in the background: auto_reply (conversation ID, mailbox
 * settings ID, customer key), fetch_profile (customer ID, key, mailbox
 * settings ID) and announce (mailbox settings ID).
 */
class NostrTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $task;

    public $params;

    public $tries = 1;

    public $timeout = 120;

    public function __construct($task, array $params)
    {
        $this->task = $task;
        $this->params = $params;
    }

    public function handle()
    {
        $cfg = null;
        try {
            $cfg_id = $this->params[$this->task === 'announce' ? 0 : ($this->task === 'auto_reply' ? 1 : 2)] ?? null;
            $cfg = $cfg_id ? NostrMailbox::find($cfg_id) : null;
            switch ($this->task) {
                case 'auto_reply':
                    (new OutgoingMessageSender(Nostr::logger()))->sendAutoReply(...$this->params);
                    break;
                case 'fetch_profile':
                    Nostr::fetchProfile(...$this->params);
                    break;
                case 'announce':
                    if ($cfg) {
                        (new Announcer(Nostr::logger()))->announce($cfg);
                    }
                    break;
            }
        } catch (\Throwable $e) {
            \App\Misc\ChatLog::failure('nostr', $cfg ? $cfg->mailbox_id : null, $this->task === 'auto_reply' ? 'send' : 'connection', $e);
            \Log::error('[Nostr] '.$this->task.' failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }
}
