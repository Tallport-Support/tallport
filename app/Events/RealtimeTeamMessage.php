<?php
/**
 * A new message in a mailbox's team chat (App\Livewire\TeamChat): the open
 * room shows it, elsewhere the sidebar's unread badge follows.
 */
namespace App\Events;

use App\Mailbox;
use App\TeamMessage;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class RealtimeTeamMessage implements ShouldBroadcastNow
{
    use SerializesModels;

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

    public static function dispatchSelf(TeamMessage $message)
    {
        event(new self([
            'mailbox_id'      => $message->mailbox_id,
            'team_message_id' => $message->id,
            'user_id'         => $message->user_id,
        ]));
    }

    /**
     * For users who see the mailbox, with their number of unread messages in all rooms.
     */
    public static function processPayload($payload)
    {
        $user = auth()->user();
        $mailbox = Mailbox::rememberForever()->find($payload->mailbox_id ?? 0);
        if (!$user || !$mailbox || !$user->can('viewCached', $mailbox)) {
            return [];
        }
        $payload->unread = TeamMessage::unreadTotal($user);

        return $payload;
    }
}
