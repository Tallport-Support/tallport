<?php

namespace App\Notifications;

use App\Subscription;
use App\TeamMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * A user was mentioned in a mailbox's team chat (App\Livewire\TeamChat): in
 * the notifications menu, and from the browser for users who get browser
 * notifications. Opening the room marks it read.
 */
class TeamMentionNotification extends Notification
{
    public $team_message;

    public function __construct(TeamMessage $team_message)
    {
        $this->team_message = $team_message;
    }

    public function via($user)
    {
        return ['database', \App\Channels\RealtimeBroadcastChannel::class];
    }

    /**
     * The mailbox first: App\Livewire\TeamChat finds a room's mentions by it.
     */
    public function toArray($user)
    {
        return [
            'mailbox_id'      => $this->team_message->mailbox_id,
            'team_message_id' => $this->team_message->id,
        ];
    }

    public function toBroadcast($user)
    {
        return new BroadcastMessage([
            'team_message_id' => $this->team_message->id,
            'browser'         => Subscription::where('user_id', $user->id)->where('medium', Subscription::MEDIUM_BROWSER)->exists(),
        ]);
    }

    /**
     * The notifications menu's entries for mentions: [team_message_id => entry].
     */
    public static function entries($notifications, $user)
    {
        $ids = collect($notifications)->pluck('data.team_message_id')->filter()->all();
        if (!$ids) {
            return [];
        }
        $messages = TeamMessage::whereIn('id', $ids)->with(['user', 'mailbox'])->get()->keyBy('id');

        $entries = [];
        foreach ($notifications as $notification) {
            $message = $messages->get($notification->data['team_message_id'] ?? 0);
            // Gone, or a mailbox the user no longer sees.
            if (!$message || !$message->mailbox || !$user->can('viewCached', $message->mailbox)) {
                continue;
            }
            $entries[$notification->id] = [
                'notification'     => $notification,
                'created_at'       => $notification->created_at,
                'team_message'     => $message,
                'last_thread_body' => $message->body,
            ];
        }

        return $entries;
    }

    /**
     * What a user gets as it happens (App\Notifications\BroadcastNotification::fetchPayloadData()).
     */
    public static function fetchPayloadData($payload)
    {
        $user = auth()->user();
        $entries = $user ? self::entries([(object) ['id' => $payload->id ?? null, 'created_at' => now(), 'read_at' => null, 'data' => ['team_message_id' => $payload->team_message_id]]], $user) : [];
        $entry = reset($entries);
        if (!$entry) {
            return [];
        }

        $data['web']['html'] = view('users/partials/web_notifications', ['web_notifications_info_data' => [$entry]])->render();
        if (!empty($payload->browser)) {
            $data['browser']['text'] = self::description($entry['team_message']);
            $data['browser']['url'] = route('mailboxes.team_chat', ['id' => $entry['team_message']->mailbox_id]);
        }

        return $data;
    }

    /**
     * "Ann mentioned you in Support Team Chat".
     */
    public static function description(TeamMessage $message)
    {
        return __(':person mentioned you in :mailbox Team Chat', [
            'person'  => $message->user ? $message->user->getFullName() : '',
            'mailbox' => $message->mailbox ? $message->mailbox->name : '',
        ]);
    }
}
