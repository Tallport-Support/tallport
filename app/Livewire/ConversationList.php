<?php

namespace App\Livewire;

use App\Conversation;
use App\Folder;
use App\Http\Controllers\ConversationsController;
use App\Mailbox;
use App\Misc\AllMailboxes;
use FruitUI\Fruit;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A list of conversations: a folder's (the mailbox page, beside an open
 * conversation), search results or a customer's conversations.
 *
 * It keeps what the list shows and renders conversations/conversations_table
 * with the queries and hooks of the conversations_pagination ajax action.
 */
class ConversationList extends Component
{
    #[Locked]
    public $mailbox_id;

    #[Locked]
    public $folder_id;

    #[Locked]
    public $filter = [];

    #[Locked]
    public $params = [];

    #[Locked]
    public $sorting = [];

    #[Locked]
    public $page = 1;

    /**
     * The query parameter the page is kept in (page, or list_page beside
     * a conversation), or none.
     */
    #[Locked]
    public $page_param;

    #[Locked]
    public $url;

    /**
     * The checked conversations (FruitUI's list selection), for the bulk actions.
     */
    public $selected = [];

    /**
     * The conversations the page has loaded already, for the first render.
     */
    protected $initial;

    public function mount($conversations = null, $folder = null, $mailbox = null, $params = [], $filter = [], $pageParam = null)
    {
        $this->initial = $conversations;
        if ($folder && $folder->id) {
            $this->folder_id = $folder->id;
            $this->mailbox_id = $folder->id < 0 ? AllMailboxes::MAILBOX_ID : $folder->mailbox_id;
        } elseif ($mailbox) {
            $this->mailbox_id = $mailbox->id;
        }
        if (request()->x_embed) {
            $params['target_blank'] = true;
        }
        $this->params = $params;
        $this->filter = $filter;
        $this->sorting = Conversation::getConvTableSorting();
        $this->page = $conversations && method_exists($conversations, 'currentPage') ? $conversations->currentPage() : 1;
        $this->page_param = $pageParam;
        $this->url = request()->fullUrl();
    }

    public function sort($sort_by)
    {
        if (!in_array($sort_by, ['date', 'number', 'subject'])) {
            return;
        }
        $order = 'asc';
        if (($this->sorting['sort_by'] ?? '') == $sort_by && ($this->sorting['order'] ?? '') == 'asc') {
            $order = 'desc';
        }
        $this->sorting = ['sort_by' => $sort_by, 'order' => $order];
        $this->selected = [];
    }

    public function gotoPage($page)
    {
        $this->page = max(1, (int) $page);
        $this->selected = [];
        if ($this->page_param) {
            $this->js('window.history.replaceState({}, "", (url => (url.searchParams.set('.json_encode($this->page_param).', '.$this->page.'), url))(new URL(window.location)))');
        }
    }

    /**
     * Only the conversations assigned to a user; none: everyone's.
     */
    public function filterAssignee($user_id = null)
    {
        $params = $this->params;
        if ($user_id) {
            $params['user_id'] = (int) $user_id;
        } else {
            unset($params['user_id']);
        }
        $this->params = $params;
        $this->page = 1;
        $this->selected = [];
    }

    public function star($conversation_id)
    {
        $user = auth()->user();
        $conversation = Conversation::find($conversation_id);
        if (!$conversation || !$user->can('view', $conversation)) {
            Fruit::toast(__('Not enough permissions'), 'danger');

            return;
        }

        if ($conversation->isStarredByUser($user->id)) {
            $conversation->unstar($user);
        } else {
            $conversation->star($user);
        }
    }

    public function assign($user_id)
    {
        Conversation::bulkChangeUser((array) $this->selected, $user_id, auth()->user());

        $this->reload(__('Assignee updated'));
    }

    public function changeStatus($status)
    {
        if (!array_key_exists((int) $status, Conversation::$statuses)) {
            Fruit::toast(__('Incorrect status'), 'danger');

            return;
        }
        Conversation::bulkChangeStatus((array) $this->selected, $status, auth()->user());

        $this->reload(__('Status updated'));
    }

    public function delete()
    {
        $user = auth()->user();
        if (!$user->can('delete', new Conversation())) {
            Fruit::toast(__('Not enough permissions'), 'danger');

            return;
        }
        Conversation::bulkDelete((array) $this->selected, $user);

        $this->reload(__('Conversations deleted'));
    }

    /**
     * Another conversation opened beside the list (App\Livewire\ConversationPane): its row
     * is the current one (marked by the script meanwhile).
     */
    #[On('conversation-open')]
    public function conversationOpened($id)
    {
        $this->params['current_conversation_id'] = (int) $id;
        $this->skipRender();
    }

    /**
     * Another folder opened in place (public/js/tallport.js): its conversations, from
     * the first page, with the one it opens at current (App\Livewire\ConversationPane).
     */
    #[On('folder-open')]
    public function openFolder($folder_id, $conversation_id = null)
    {
        $folder = ConversationOpens::findFolder($folder_id);
        $user = auth()->user();
        if (!$folder) {
            $this->skipRender();

            return;
        }
        $list = ConversationsController::folderList($folder, $user);
        $this->folder_id = $folder->id;
        $this->mailbox_id = $folder->id < 0 ? AllMailboxes::MAILBOX_ID : $folder->mailbox_id;
        $this->params = array_intersect_key($this->params, ['target_blank' => 1]) + $list['params']
            + ['current_conversation_id' => $conversation_id ?: ConversationsController::folderConversationId($folder, $user)];
        $this->filter = [];
        $this->page = 1;
        $this->selected = [];
        $this->url = $folder->id < 0 ? route('mailboxes.all', ['folder_id' => $folder->id]) : $folder->url($folder->mailbox_id);
    }

    /**
     * New conversations or changes in the list (realtime events).
     */
    #[On('conversations-changed')]
    public function refresh()
    {
    }

    /**
     * Reloads the page after a bulk action, so the folders' counters follow.
     */
    protected function reload($message)
    {
        Fruit::flashToast($message, 'success');
        $this->redirect($this->url);
    }

    public function render()
    {
        $user = auth()->user();
        if (!$user) {
            abort(401);
        }

        $list = [
            'folder'               => $this->folder_id ? $this->folder() : null,
            'conversations'        => $this->initial,
            'conversations_filter' => $this->filter['f'] ?? $this->filter,
        ];
        if (!$this->initial) {
            // The list's query reads these from the request, as the ajax action did.
            request()->merge([
                'mailbox_id' => $this->mailbox_id,
                'folder_id'  => $this->folder_id,
                'filter'     => $this->filter,
                'params'     => $this->params,
                'sorting'    => $this->sorting,
                'page'       => $this->page,
            ]);
            $list = app(ConversationsController::class)->listConversations(request(), $user);
            if (!empty($list['msg'])) {
                abort(403, $list['msg']);
            }
        }

        $mailbox = null;
        if (AllMailboxes::isAllMailboxes($this->mailbox_id)) {
            $mailbox = AllMailboxes::mailbox();
        } elseif ($this->mailbox_id) {
            $mailbox = Mailbox::find($this->mailbox_id);
        }

        return view('livewire.conversation-list', [
            'folder'               => $list['folder'],
            'conversations'        => $list['conversations'],
            'conversations_filter' => $list['conversations_filter'],
            'params'               => $this->params,
            'mailbox'              => $mailbox,
        ]);
    }

    protected function folder()
    {
        if ($this->folder_id < 0) {
            return AllMailboxes::folder(auth()->user(), $this->folder_id);
        }

        return Folder::find($this->folder_id);
    }
}
