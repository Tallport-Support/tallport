<?php

namespace App\Livewire;

use App\Attachment;
use App\Mailbox;
use App\TeamMessage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A team chat's details (mailboxes/team_chat, in the inspector's place): its
 * people, pinned messages and recent files. Follows the room (App\Livewire\TeamChat).
 */
class TeamChatDetails extends Component
{
    /**
     * How many files Recent Files lists.
     */
    const FILES = 6;

    #[Locked]
    public $mailbox_id;

    public function mount(Mailbox $mailbox)
    {
        $this->mailbox_id = $mailbox->id;
    }

    /**
     * A message sent, pinned or unpinned, here or by someone else (realtime).
     */
    #[On('team-message-created')]
    #[On('team-chat-changed')]
    public function refresh()
    {
    }

    public function render()
    {
        $mailbox = Mailbox::find($this->mailbox_id);
        abort_unless($mailbox && auth()->user()->can('viewCached', $mailbox), 403);

        $files = Attachment::whereIn('team_message_id', TeamMessage::where('mailbox_id', $mailbox->id)->select('id'))
            ->orderBy('id', 'desc')->limit(self::FILES)->get();

        return view('livewire.team-chat-details', [
            'members' => TeamMessage::members($mailbox),
            'pinned'  => TeamMessage::where('mailbox_id', $mailbox->id)->whereNotNull('pinned_at')->with(['user', 'attachments'])->orderBy('id')->get(),
            'files'   => $files,
            'authors' => \App\User::whereIn('id', $files->pluck('user_id')->filter()->unique())->get()->keyBy('id'),
        ]);
    }
}
