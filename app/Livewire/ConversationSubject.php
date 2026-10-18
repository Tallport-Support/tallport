<?php

namespace App\Livewire;

use App\Conversation;
use App\Misc\ConversationActions;
use App\Misc\Sidebar;
use FruitUI\Fruit;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The open conversation's heading: its status and number, its mailbox, who else is
 * viewing it (updated by the realtime script), the star and the subject, which a user
 * may edit, then who wrote in and the AI summary. A bell marks the conversations the
 * user follows. Compact (the chat view): the status, viewers and star without the rest.
 */
class ConversationSubject extends Component
{
    #[Locked]
    public $conversation_id;

    #[Locked]
    public $compact = false;

    /**
     * The users viewing the conversation when the page loaded.
     */
    protected $viewers = [];

    public function mount($conversation, $viewers = [], $compact = false)
    {
        $this->conversation_id = $conversation->id;
        $this->compact = (bool) $compact;
        $this->viewers = $viewers;
    }

    /**
     * Stars or unstars the conversation (the button, or *).
     */
    public function star()
    {
        $user = auth()->user();
        $conversation = $this->conversation();
        $starred = !$conversation->isStarredByUser($user->id);
        if ($starred) {
            $conversation->star($user);
        } else {
            $conversation->unstar($user);
        }
        $this->dispatch('sidebar-counts', counts: Sidebar::unreadCounts($user));
        Fruit::toast($starred ? __('Starred') : __('Unstarred'));
    }

    public function saveSubject($subject)
    {
        $result = ConversationActions::changeSubject($this->conversation(), $subject, auth()->user());
        if (!empty($result['msg'])) {
            Fruit::toast($result['msg'], 'danger');
        }
    }

    /**
     * A new message, or the AI summary ready (public/js/realtime.js): the summary may have changed.
     */
    #[On('conversation-thread-created')]
    public function refreshSummary()
    {
    }

    /**
     * Following changed in the toolbar.
     */
    #[On('conversation-followed')]
    public function followed()
    {
    }

    protected function conversation()
    {
        $conversation = Conversation::find($this->conversation_id);
        if (!$conversation || !auth()->user() || !auth()->user()->can('view', $conversation)) {
            abort(403);
        }

        return $conversation;
    }

    public function render()
    {
        $conversation = $this->conversation();

        return view('livewire.conversation-subject', [
            'conversation' => $conversation,
            'viewers'      => $this->viewers ?? [],
            'is_following' => $conversation->isUserFollowing(auth()->id()),
            'starred'      => $conversation->isStarredByUser(auth()->id()),
            'mailbox'      => $conversation->mailbox,
            'customer'     => $this->compact ? null : $conversation->customer,
        ]);
    }
}
