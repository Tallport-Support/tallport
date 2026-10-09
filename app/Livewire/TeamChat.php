<?php

namespace App\Livewire;

use App\Attachment;
use App\Mailbox;
use App\TeamMessage;
use FruitUI\Fruit;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
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
     * How many messages the room keeps in its visible history, and how far each page moves.
     */
    const SHOWN = 300;
    const PAGE = 100;

    #[Locked]
    public $mailbox_id;

    /**
     * The first message that was new when the room opened (the "New" divider), or 0.
     */
    #[Locked]
    public $first_new_id = 0;

    /**
     * The newest message in an older window; zero follows the latest messages.
     */
    #[Locked]
    public $window_end_id = 0;

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
     * A new message in the room (realtime): show it; the browser reports when it is visible.
     */
    #[On('team-message-created')]
    public function refresh()
    {
        $this->dispatch('team-chat-refreshed');
    }

    #[Renderless]
    public function markSeenThrough($id)
    {
        $mailbox = $this->mailbox();
        if ($this->window_end_id) {
            return;
        }
        $message = TeamMessage::where('mailbox_id', $mailbox->id)->findOrFail($id);
        $this->markRead($message->id);
        $this->dispatch('team-chat-unread', unread: TeamMessage::unreadTotal(auth()->user()));
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

    public function loadOlder()
    {
        $mailbox = $this->mailbox();
        $query = TeamMessage::where('mailbox_id', $mailbox->id);
        if ($this->window_end_id) {
            $query->where('id', '<=', $this->window_end_id);
        }
        $ids = $query->orderBy('id', 'desc')->limit(self::SHOWN)->pluck('id');
        if ($ids->count() == self::SHOWN && TeamMessage::where('mailbox_id', $mailbox->id)->where('id', '<', $ids->last())->exists()) {
            $this->window_end_id = $ids[self::PAGE];
        }
    }

    public function loadNewer()
    {
        $mailbox = $this->mailbox();
        if (!$this->window_end_id) {
            return;
        }
        $ids = TeamMessage::where('mailbox_id', $mailbox->id)->where('id', '>', $this->window_end_id)
            ->orderBy('id')->limit(self::PAGE)->pluck('id');
        $this->window_end_id = $ids->count() == self::PAGE && TeamMessage::where('mailbox_id', $mailbox->id)->where('id', '>', $ids->last())->exists()
            ? $ids->last() : 0;
    }

    public function showLatest()
    {
        $this->mailbox();
        $this->window_end_id = 0;
    }

    #[On('team-chat-jump')]
    public function jumpTo($id)
    {
        $mailbox = $this->mailbox();
        $message = TeamMessage::where('mailbox_id', $mailbox->id)->whereNotNull('pinned_at')->findOrFail($id);
        $newer = TeamMessage::where('mailbox_id', $mailbox->id)->where('id', '>', $message->id)
            ->orderBy('id')->limit(intdiv(self::SHOWN, 2))->pluck('id');
        $this->window_end_id = $newer->count() == intdiv(self::SHOWN, 2) ? $newer->last() : 0;
        $this->dispatch('team-chat-focus', id: $message->id);
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
        $saved_files = [];
        try {
            $message = \DB::transaction(function () use ($mailbox, $user, $body, &$saved_files) {
                $message = TeamMessage::create(['mailbox_id' => $mailbox->id, 'user_id' => $user->id, 'body' => $body]);
                // Files are stored encrypted, as the text (OpenController::downloadAttachment() decrypts them).
                foreach ($this->files as $file) {
                    $mime_type = $file->getMimeType();
                    $file_name = \Helper::sanitizeUploadedFileName($file->getClientOriginalName(), $file, null, $mime_type);
                    $attachment = Attachment::create($file_name, $mime_type, null, \Crypt::encryptString($file->get()), null, false, null, $user->id, \Helper::UPLOAD_MODE_DEFAULT, true);
                    if (!$attachment || !$attachment->fileExists()) {
                        throw new \RuntimeException('Could not store team chat attachment.');
                    }
                    $saved_files[] = $attachment->getStorageFilePath();
                    $attachment->team_message_id = $message->id;
                    $attachment->size = $file->getSize();
                    $attachment->save();
                }

                return $message;
            });
        } catch (\Throwable $e) {
            foreach ($saved_files as $path) {
                try {
                    Attachment::getDisk()->delete($path);
                } catch (\Throwable $cleanup_error) {
                    \Helper::logException($cleanup_error, '[TeamChat::send()]');
                }
            }
            \Helper::logException($e, '[TeamChat::send()]');
            Fruit::toast(__('Error occurred. Please try again later.'), 'danger');

            return;
        }
        $this->body = '';
        $this->files = [];
        $this->first_new_id = 0;
        $this->window_end_id = 0;
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

    protected function markRead($through_id = null)
    {
        $user = auth()->user();
        TeamMessage::markRead($this->mailbox_id, $user->id, $through_id);
        // Mentions in this room: seen.
        $mentions = $user->unreadNotifications()->where('type', \App\Notifications\TeamMentionNotification::class)
            ->where('data', 'like', '%"mailbox_id":'.(int) $this->mailbox_id.',%');
        if ($through_id !== null) {
            $ids = $mentions->get(['id', 'data'])->filter(function ($notification) use ($through_id) {
                return !empty($notification->data['team_message_id']) && $notification->data['team_message_id'] <= $through_id;
            })->pluck('id');
            $marked = $ids->isNotEmpty() ? $user->unreadNotifications()->whereIn('id', $ids)->update(['read_at' => now()]) : 0;
        } else {
            $marked = $mentions->update(['read_at' => now()]);
        }
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
        $query = TeamMessage::where('mailbox_id', $mailbox->id)->with(['user', 'attachments']);
        if ($this->window_end_id) {
            $query->where('id', '<=', $this->window_end_id);
        }
        $messages = $query->orderBy('id', 'desc')->limit(self::SHOWN)->get()->reverse()->values();
        if ($messages->isEmpty() && $this->window_end_id) {
            $this->window_end_id = 0;
            $messages = TeamMessage::where('mailbox_id', $mailbox->id)->with(['user', 'attachments'])
                ->orderBy('id', 'desc')->limit(self::SHOWN)->get()->reverse()->values();
        }
        $has_older = $messages->isNotEmpty() && TeamMessage::where('mailbox_id', $mailbox->id)->where('id', '<', $messages->first()->id)->exists();
        $has_newer = $this->window_end_id && $messages->isNotEmpty()
            && TeamMessage::where('mailbox_id', $mailbox->id)->where('id', '>', $messages->last()->id)->exists();

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
            'mailbox'   => $mailbox,
            'members'   => $members,
            'sections'  => $sections,
            'has_older' => $has_older,
            'has_newer' => $has_newer,
            'last_id'   => $messages->isNotEmpty() ? $messages->last()->id : 0,
            'last_read_id' => TeamMessage::lastReadId($mailbox->id, $user->id),
        ]);
    }
}
