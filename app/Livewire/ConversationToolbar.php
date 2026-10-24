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
 * Another conversation opens in it in place (ConversationOpens); its actions'
 * links are built for the folder it was opened from (actionRequest()).
 */
class ConversationToolbar extends Component
{
    // The conversation, and the folder it was opened from: where the user goes after a
    // change (ConversationActions::redirectUrl()); another conversation opens in place.
    use ConversationOpens;

    /**
     * The embedded view.
     */
    #[Locked]
    public $x_embed;

    public function mount($conversation = null, $folder = null)
    {
        $this->conversation_id = $conversation ? $conversation->id : null;
        // The folder it's shown beside (where Delete goes on), as the page's.
        $this->folder_id = $folder ? $folder->id : self::openedFolderId($conversation, null);
        $this->x_embed = request()->x_embed;
    }

    public function assign($user_id)
    {
        $this->changed(ConversationActions::changeUser($this->conversation(), $user_id, auth()->user(), $this->actionRequest()));
    }

    public function changeStatus($status)
    {
        $this->changed(ConversationActions::changeStatus($this->conversation(), $status, auth()->user(), $this->actionRequest()));
    }

    public function restore()
    {
        $this->done(ConversationActions::restore($this->conversation(), auth()->user()), $this->conversation()->url());
    }

    /**
     * Legal hold on or off (administrators): retention never deletes it.
     */
    public function toggleHold()
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $conversation = $this->conversation();
        $conversation->retention_hold_at = $conversation->retention_hold_at ? null : now();
        $conversation->retention_hold_by = $conversation->retention_hold_at ? auth()->id() : null;
        $conversation->save();
        Fruit::toast($conversation->retention_hold_at ? __('On legal hold: it will not be deleted.') : __('Legal hold lifted.'));
    }

    /**
     * A website chat's visitor blocked or unblocked (App\WebChat\Spam): their messages taken and dropped.
     */
    public function toggleBlockVisitor()
    {
        $conversation = $this->conversation();
        if (\App\WebChat\Spam::isBlocked($conversation)) {
            \App\WebChat\Spam::unblock($conversation);
            Fruit::toast(__('Visitor unblocked.'));
        } else {
            \App\WebChat\Spam::block($conversation);
            Fruit::toast(__('Visitor blocked: their messages are dropped.'));
        }
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

    /**
     * Deleted (its row gone at once in the browser): on to the next conversation of the folder
     * being viewed (Mine, Starred, All Mailboxes… not its own folder), else that folder.
     */
    public function delete()
    {
        $conversation = $this->conversation();
        $folder = $conversation ? $this->openedFolder() : null;
        // Found before it's gone.
        $next = $folder ? $conversation->getNearby('next', $folder->id, null, true) : null;
        $result = ConversationActions::delete($conversation, auth()->user(), $conversation && $conversation->state == Conversation::STATE_DELETED);
        if (!empty($result['msg']) && empty($result['status'])) {
            Fruit::toast($result['msg'], 'danger');
            $this->dispatch('conversations-changed');

            return;
        }
        \Session::forget('flash_success_floating');
        Fruit::toast(__('Conversation deleted'), 'success');
        $this->dispatch('sidebar-counts', counts: \App\Misc\Sidebar::unreadCounts(auth()->user()));
        $this->dispatch('conversations-changed');
        if ($next) {
            $this->dispatch('conversation-next', id: $next->id);
        } else {
            $this->redirect($folder ? ($folder->id < 0 ? route('mailboxes.all', ['folder_id' => $folder->id]) : $folder->url($folder->mailbox_id)) : $result['redirect_url'], navigate: true);
        }
    }

    /**
     * Its assignee or status changed (shown at once in the browser, public/js/conversations.js):
     * the page follows in place: the toolbar, the conversation, the list and the counters; on
     * to the next conversation where the action leads there. Refused: the toolbar and the list
     * as they are (the browser's change undone).
     */
    protected function changed($result)
    {
        if (!empty($result['msg']) && empty($result['status'])) {
            Fruit::toast($result['msg'], 'danger');
            $this->dispatch('conversations-changed');

            return;
        }
        // No page load to show the action's message on.
        \Session::forget('flash_success_floating');
        Fruit::toast($result['msg'], 'success');
        $conversation = $this->conversation();
        $this->dispatch('sidebar-counts', counts: \App\Misc\Sidebar::unreadCounts(auth()->user()));
        $this->dispatch('conversation-changed', ids: [$conversation->id]);
        $this->dispatch('conversations-changed');
        $url = $result['redirect_url'] ?? $conversation->url();
        if ($url != $conversation->url()) {
            if (preg_match('#/conversation/(\d+)#', $url, $next)) {
                $this->dispatch('conversation-next', id: (int) $next[1]);
            } else {
                $this->redirect($url, navigate: true);
            }
        }
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
        abort_unless(auth()->user(), 403);

        return $this->shownConversation();
    }

    /**
     * Changed from the list (ConversationList::changed()): shown anew when it's this one.
     */
    #[\Livewire\Attributes\On('conversation-changed')]
    public function conversationChanged($ids)
    {
        if (!in_array((int) $this->conversation_id, array_map('intval', (array) $ids), true)) {
            $this->skipRender();
        }
    }

    public function render()
    {
        if (!$this->conversation_id) {
            return view('livewire.conversation-toolbar-none');
        }
        $conversation = $this->conversation();
        $user = auth()->user();
        $mailbox = $conversation->mailbox;
        $this->actionRequest();
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
