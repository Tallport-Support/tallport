<?php

namespace App\Livewire;

use App\Attachment;
use App\Mailbox;
use App\TeamMessage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A mailbox's team chat (mailboxes/team_chat): the room's history, a divider
 * per day and one at the first message that was new when it opened, and the
 * composer docked below (FruitUI's history, compact thread and composer).
 * Opening the room marks it read; new messages arrive by the realtime script
 * (team-message-created). A mention notifies the agent.
 */
class TeamChat extends Component
{
    use WithFileUploads;

    /**
     * How many of the latest messages the room shows.
     */
    const SHOWN = 300;

    #[Locked]
    public $mailbox_id;

    /**
     * The first message that was new when the room opened (the "New" divider), or 0.
     */
    #[Locked]
    public $first_new_id = 0;

    public $body = '';

    /**
     * Files to send with the next message.
     */
    public $files = [];

    public function mount(Mailbox $mailbox)
    {
        $this->mailbox_id = $mailbox->id;
        $user = auth()->user();
        $this->first_new_id = (int) TeamMessage::where('mailbox_id', $mailbox->id)->where('user_id', '!=', $user->id)
            ->where('id', '>', TeamMessage::lastReadId($mailbox->id, $user->id))->min('id');
        $this->markRead();
    }

    /**
     * A new message in the room (realtime): shown, and read.
     */
    #[On('team-message-created')]
    public function refresh()
    {
        $this->markRead();
    }

    /**
     * Pinned for everyone in the room (the details list them), or not any more.
     */
    public function togglePin($id)
    {
        $message = TeamMessage::where('mailbox_id', $this->mailbox()->id)->findOrFail($id);
        $message->pinned_at = $message->pinned_at ? null : now();
        $message->pinned_by_user_id = $message->pinned_at ? auth()->id() : null;
        $message->save();
        $this->dispatch('team-chat-changed');
        \App\Events\RealtimeTeamMessage::dispatchSelf($message);
    }

    public function removeFile($index)
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function send()
    {
        $mailbox = $this->mailbox();
        $this->validate([
            'body'    => 'nullable|string|max:20000',
            'files'   => 'array|max:10',
            'files.*' => 'file|max:'.(int) (config('app.max_message_size') ?: 20) * 1024,
        ]);
        $body = trim((string) $this->body);
        if ($body === '' && !$this->files) {
            return;
        }
        $user = auth()->user();
        $message = TeamMessage::create(['mailbox_id' => $mailbox->id, 'user_id' => $user->id, 'body' => $body]);
        // Files are stored encrypted, as the text (OpenController::downloadAttachment() decrypts them).
        foreach ($this->files as $file) {
            $mime_type = $file->getMimeType();
            $file_name = \Helper::sanitizeUploadedFileName($file->getClientOriginalName(), $file, null, $mime_type);
            $attachment = Attachment::create($file_name, $mime_type, null, \Crypt::encryptString($file->get()), null, false, null, $user->id);
            if ($attachment) {
                $attachment->team_message_id = $message->id;
                $attachment->size = $file->getSize();
                $attachment->save();
            }
        }
        $this->body = '';
        $this->files = [];
        $this->first_new_id = 0;
        $this->markRead();

        // Mentioned teammates are notified; everyone's room and unread count follow.
        foreach (TeamMessage::mentioned($body, TeamMessage::members($mailbox)) as $member) {
            if ($member->id != $user->id) {
                $member->notify(new \App\Notifications\TeamMentionNotification($message));
                $member->clearWebsiteNotificationsCache();
            }
        }
        $this->dispatch('team-chat-changed');
        \App\Events\RealtimeTeamMessage::dispatchSelf($message);
        \Eventy::action('team_chat.message_created', $message);
    }

    protected function markRead()
    {
        $user = auth()->user();
        TeamMessage::markRead($this->mailbox_id, $user->id);
        // Mentions in this room: seen.
        $marked = $user->unreadNotifications()->where('type', \App\Notifications\TeamMentionNotification::class)
            ->where('data', 'like', '%"mailbox_id":'.(int) $this->mailbox_id.',%')->update(['read_at' => now()]);
        if ($marked) {
            $user->clearWebsiteNotificationsCache();
        }
    }

    protected function mailbox()
    {
        $mailbox = Mailbox::find($this->mailbox_id);
        abort_unless($mailbox && auth()->user() && auth()->user()->can('viewCached', $mailbox), 403);

        return $mailbox;
    }

    public function render()
    {
        $mailbox = $this->mailbox();
        $user = auth()->user();
        $members = TeamMessage::members($mailbox);
        $messages = TeamMessage::where('mailbox_id', $mailbox->id)->with(['user', 'attachments'])
            ->orderBy('id', 'desc')->limit(self::SHOWN)->get()->reverse()->values();

        // Sections: a day each, and "New" from the first new message on.
        $sections = [];
        $previous = null;
        foreach ($messages as $message) {
            $key = $this->first_new_id && $message->id >= $this->first_new_id ? 'new' : \App\Misc\Helper::userDate($message->created_at);
            if (!isset($sections[$key])) {
                $sections[$key] = [
                    'label'    => $key == 'new' ? __('New') : \App\Misc\Helper::dayName($message->created_at),
                    'new'      => $key == 'new',
                    'messages' => [],
                ];
                $previous = null;
            }
            $sections[$key]['messages'][] = [
                'message'   => $message,
                'html'      => TeamMessage::html($message->body, $mailbox, $user, $members),
                // A run: the same person, minutes apart; a pinned message starts its own (its name, time and pin show).
                'continued' => $previous && $previous->user_id == $message->user_id && $message->created_at->diffInSeconds($previous->created_at, true) < 300 && !$message->pinned_at,
            ];
            $previous = $message;
        }

        return view('livewire.team-chat', [
            'mailbox'  => $mailbox,
            'members'  => $members,
            'sections' => $sections,
        ]);
    }
}
