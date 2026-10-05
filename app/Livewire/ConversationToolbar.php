<?php

namespace App\Livewire;

use App\Conversation;
use App\Misc\ConversationActions;
use FruitUI\Fruit;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The open conversation's toolbar: its actions (App\Misc\ConversationActionButtons,
 * with the modules' hooks), the assignee, the status and the way to the
 * newer and older conversations.
 *
 * It renders once: the actions' links come from the page's request, and every
 * action but following leads to another page.
 */
class ConversationToolbar extends Component
{
    #[Locked]
    public $conversation_id;

    /**
     * The folder the conversation was opened from, and the embedded view:
     * where the user goes after a change (ConversationActions::redirectUrl()).
     */
    #[Locked]
    public $folder_id;

    #[Locked]
    public $x_embed;

    public function mount($conversation)
    {
        $this->conversation_id = $conversation->id;
        $this->folder_id = Conversation::getFolderParam();
        $this->x_embed = request()->x_embed;
    }

    public function assign($user_id)
    {
        $this->done(ConversationActions::changeUser($this->conversation(), $user_id, auth()->user(), $this->actionRequest()));
    }

    public function changeStatus($status)
    {
        $this->done(ConversationActions::changeStatus($this->conversation(), $status, auth()->user(), $this->actionRequest()));
    }

    public function restore()
    {
        $this->done(ConversationActions::restore($this->conversation(), auth()->user()), $this->conversation()->url());
    }

    /**
     * Chat Mode on or off (for the session), then the conversation again.
     */
    public function toggleChatMode()
    {
        $conversation = $this->conversation();
        \Helper::setChatMode(!\Helper::isChatMode());

        $this->redirect($conversation->url($this->folder_id), navigate: true);
    }

    /**
     * The menu shows Follow or Unfollow by itself (Alpine).
     */
    public function follow($follow = true)
    {
        $this->skipRender();
        $result = ConversationActions::follow($this->conversation(), auth()->user(), (bool) $follow);
        if (!empty($result['msg'])) {
            Fruit::toast($result['msg'], 'danger');

            return false;
        }
        Fruit::toast($result['msg_success'], 'success');
        $this->dispatch('conversation-followed', following: (bool) $follow);

        return true;
    }

    public function delete()
    {
        $conversation = $this->conversation();
        $this->done(ConversationActions::delete($conversation, auth()->user(), $conversation && $conversation->state == Conversation::STATE_DELETED));
    }

    /**
     * Shows the error, or goes where the action leads (the action flashed its message).
     */
    protected function done($result, $redirect_url = null)
    {
        if (!empty($result['msg']) && empty($result['status'])) {
            $this->skipRender();
            Fruit::toast($result['msg'], 'danger');

            return;
        }
        $this->redirect($result['redirect_url'] ?? $redirect_url);
    }

    /**
     * The request the actions read: the folder and the embedded view the page was opened with.
     */
    protected function actionRequest()
    {
        request()->merge(['folder_id' => $this->folder_id, 'x_embed' => $this->x_embed]);

        return request();
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
        $user = auth()->user();
        $mailbox = $conversation->mailbox;
        $actions = \App\Misc\ConversationActionButtons::getActions($conversation, $user, $mailbox);

        return view('livewire.conversation-toolbar', [
            'conversation'     => $conversation,
            'mailbox'          => $mailbox,
            'is_following'     => $conversation->isUserFollowing($user->id),
            'toolbar_actions'  => \App\Misc\ConversationActionButtons::getActionsByLocation($actions, \App\Misc\ConversationActionButtons::LOCATION_TOOLBAR),
            'dropdown_actions' => \App\Misc\ConversationActionButtons::getActionsByLocation($actions, \App\Misc\ConversationActionButtons::LOCATION_DROPDOWN),
        ]);
    }
}
