<?php

namespace App\Events;

use App\Mailbox;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A hint, no data: a mailbox's conversations changed (App\Inbox\Changes), so the browsers' local
 * inbox syncs (public/js/inbox.js).
 */
class RealtimeInboxChanged implements ShouldBroadcastNow
{
    public $data = [];

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function broadcastOn()
    {
        return new \Illuminate\Broadcasting\Channel('mailbox.'.(int) ($this->data['mailbox_id'] ?? 0));
    }

    public function broadcastWith()
    {
        return $this->data;
    }

    public static function dispatchSelf($mailbox_id)
    {
        event(new self(['mailbox_id' => (int) $mailbox_id]));
    }

    public static function processPayload($payload)
    {
        $user = auth()->user();
        $mailbox = Mailbox::rememberForever()->find($payload->mailbox_id);

        return $user && $mailbox && $user->can('viewCached', $mailbox) ? ['mailbox_id' => (int) $payload->mailbox_id] : [];
    }
}
