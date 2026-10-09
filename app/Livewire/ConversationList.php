<?php

namespace App\Livewire;

use App\Conversation;
use App\ConversationRead;
use App\Folder;
use App\Mailbox;
use App\Misc\AllMailboxes;
use App\Misc\ConversationListQuery;
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
        $this->sorting = Conversation::getConvTableSorting(null, $folder && $folder->id ? $folder : null);
        $this->page = $conversations && method_exists($conversations, 'currentPage') ? $conversations->currentPage() : 1;
        $this->page_param = $pageParam;
        $this->url = request()->fullUrl();
    }

    public function sort($sort_by)
    {
        if (!in_array($sort_by, ['date', 'activity', 'number', 'subject'])) {
            return;
        }
        // A date first newest-first; again: the other way.
        $order = in_array($sort_by, ['date', 'activity']) ? 'desc' : 'asc';
        if (($this->sorting['sort_by'] ?? '') == $sort_by) {
            $order = ($this->sorting['order'] ?? '') == 'asc' ? 'desc' : 'asc';
        }
        $this->sorting = ['sort_by' => $sort_by, 'order' => $order];
        $this->selected = [];
        // Remembered for this kind of folder.
        if ($this->folder_id && ($folder = $this->folder())) {
            Conversation::saveSorting(auth()->user(), $folder->type, $this->sorting);
        }
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

    /**
     * A row's menu (conversations_table): stars or unstars the row, or the selection it's in.
     */
    public function rowStar($id, $star)
    {
        $user = auth()->user();
        foreach (Conversation::findMany($this->rowTargets($id)) as $conversation) {
            if (!$user->can('view', $conversation) || $conversation->isStarredByUser($user->id) == (bool) $star) {
                continue;
            }
            $star ? $conversation->star($user) : $conversation->unstar($user);
        }

        $this->reload($star ? __('Starred') : __('Unstarred'));
    }

    /**
     * A row's menu: marks the row, or the selection it's in, as read or unread.
     */
    public function rowRead($id, $read)
    {
        $this->markRead($read, $this->rowTargets($id));
    }

    /**
     * Marks the selected conversations (or the ones given) as read or unread; unread stays
     * until the conversation is opened again.
     */
    public function markRead($read, $ids = null)
    {
        $user = auth()->user();
        $ids = Conversation::findMany($ids ?? array_map('intval', (array) $this->selected))
            ->filter(fn ($conversation) => $user->can('view', $conversation))->pluck('id')->all();
        $read ? ConversationRead::markRead($ids, $user) : ConversationRead::markUnread($ids, $user);
    }

    /**
     * A row's menu: assigns the row, or the selection it's in, to the user.
     */
    public function rowAssignToMe($id)
    {
        Conversation::bulkChangeUser($this->rowTargets($id), auth()->id(), auth()->user());

        $this->reload(__('Assignee updated'));
    }

    /**
     * A row's menu: closes or reopens the row, or the selection it's in.
     */
    public function rowClose($id, $close)
    {
        Conversation::bulkChangeStatus($this->rowTargets($id), $close ? Conversation::STATUS_CLOSED : Conversation::STATUS_ACTIVE, auth()->user());

        $this->reload(__('Status updated'));
    }

    /**
     * What a row's menu acts on: the selection when the row is in it, else the row alone
     * (the selection stays as it is).
     */
    protected function rowTargets($id)
    {
        $selected = array_map('intval', (array) $this->selected);

        return in_array((int) $id, $selected) ? $selected : [(int) $id];
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
        // This kind of folder's order: the user's choice, or its default.
        $this->sorting = Conversation::getConvTableSorting(null, $folder);
        $list = app(ConversationListQuery::class)->folderList($folder, $user);
        $this->folder_id = $folder->id;
        $this->mailbox_id = $folder->id < 0 ? AllMailboxes::MAILBOX_ID : $folder->mailbox_id;
        $this->params = array_intersect_key($this->params, ['target_blank' => 1]) + $list['params']
            + ['current_conversation_id' => $conversation_id ?: app(ConversationListQuery::class)->folderConversationId($folder, $user)];
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
            $list = app(ConversationListQuery::class)->listConversations([
                'mailbox_id' => $this->mailbox_id,
                'folder_id'  => $this->folder_id,
                'filter'     => $this->filter,
                'params'     => $this->params,
                'sorting'    => $this->sorting,
                'page'       => $this->page,
            ], $user);
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
