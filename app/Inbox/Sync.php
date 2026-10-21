<?php

namespace App\Inbox;

use App\Conversation;
use App\ConversationRead;
use App\Folder;
use App\User;

/**
 * The browser's local copy of the inbox's list rows (public/js/inbox.js): what a user may see, as
 * a list row shows it. Kept in the browser: their unread conversations, those assigned to them and
 * not closed, those they starred, and everything from the last few days, each with a ceiling. A first sync sends the
 * folder in view first, then the rest in pages; after it, the change log (App\Inbox\Changes) says
 * which rows to send again.
 */
class Sync
{
    /**
     * What's kept: unread, theirs and open, recent (days of activity), each with its ceiling.
     */
    const RECENT_DAYS = 7;
    const MAX_UNREAD = 1000;
    const MAX_MINE = 1000;
    const MAX_STARRED = 1000;
    const MAX_RECENT = 2000;

    /**
     * Rows a page of the first sync, and changed conversations an incremental one.
     */
    const PAGE = 500;
    const CHANGES = 2000;

    protected $user;

    /**
     * Folders of the user's mailboxes: [mailbox ID => [type => folder, ...]], their own personal ones.
     */
    protected $folders = [];

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * The key the browser encrypts its copy with: one per session (a new session can't read an
     * old copy, so it starts over), never stored beyond the session.
     */
    public static function sessionKey()
    {
        if (!session('inbox_key')) {
            session(['inbox_key' => base64_encode(random_bytes(32))]);
        }

        return session('inbox_key');
    }

    /**
     * What the user may see, in short: when it changes (mailbox access, a role, "only assigned"),
     * the browser's copy starts over.
     */
    public function scope()
    {
        $mailbox_ids = $this->mailboxIds();
        sort($mailbox_ids);

        return substr(sha1(json_encode([$mailbox_ids, $this->user->isAdmin(), $this->user->canSeeOnlyAssignedConversations()])), 0, 16);
    }

    /**
     * A page of the first sync: the folder in view first (page 0), then what's kept, newest first.
     */
    public function full($page, $folder_id = null)
    {
        $cursor = Changes::latest();
        $ids = [];
        if ($page == 0 && $folder_id && ($folder = Folder::find($folder_id)) && in_array($folder->mailbox_id, $this->mailboxIds())) {
            $ids = collect((new \App\Misc\ConversationListQuery())->folderList($folder, $this->user)['conversations']->items())->pluck('id')->all();
        }
        $kept = $this->keptIds();
        $offset = max(0, $page - 1) * self::PAGE;
        $ids = array_merge($ids, $page == 0 ? array_slice($kept, 0, self::PAGE) : array_slice($kept, $offset, self::PAGE));
        $next_offset = ($page == 0 ? self::PAGE : $offset + self::PAGE);

        return [
            'cursor'  => $cursor,
            'scope'   => $this->scope(),
            'full'    => true,
            'rows'    => $this->rows(array_unique($ids)),
            'removed' => [],
            'folders' => $this->folderCounts(),
            'more'    => $next_offset < count($kept),
            'page'    => $page,
        ];
    }

    /**
     * What changed after the cursor: rows again (all the user may see; the browser keeps those
     * it should), and the IDs gone (deleted, merged, moved away or no longer theirs to see). A
     * cursor older than the change log keeps: start over.
     */
    public function since($cursor)
    {
        $cursor = (int) $cursor;
        $oldest = Changes::oldest();
        if ($oldest && $cursor < $oldest - 1) {
            return ['full' => true, 'scope' => $this->scope(), 'restart' => true];
        }
        $changes = \DB::table('inbox_changes')->where('id', '>', $cursor)->orderBy('id')->limit(self::CHANGES)->get(['id', 'conversation_id']);
        $ids = $changes->pluck('conversation_id')->unique()->values()->all();
        $rows = $this->rows($ids);

        return [
            'cursor'  => $changes->max('id') ?: $cursor,
            'scope'   => $this->scope(),
            'full'    => false,
            'rows'    => $rows,
            'removed' => array_values(array_diff($ids, array_column($rows, 'id'))),
            'folders' => $ids ? $this->folderCounts() : null,
            'more'    => $changes->count() >= self::CHANGES,
        ];
    }

    /**
     * The IDs kept in the browser, newest activity first: unread, theirs and open, starred, recent.
     */
    public function keptIds()
    {
        $ids = [];
        foreach ([
            [fn ($query) => ConversationRead::whereUnread($query, $this->user), self::MAX_UNREAD],
            [fn ($query) => $query->where('user_id', $this->user->id)->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])->where('state', Conversation::STATE_PUBLISHED), self::MAX_MINE],
            [fn ($query) => $query->whereIn('id', $this->starredIds()), self::MAX_STARRED],
            [fn ($query) => $query->where('last_activity_at', '>=', now()->subDays(self::RECENT_DAYS)), self::MAX_RECENT],
        ] as [$condition, $limit]) {
            $query = $this->visible(Conversation::query());
            $condition($query);
            foreach ($query->orderBy('last_activity_at', 'desc')->limit($limit)->get(['id', 'last_activity_at']) as $conversation) {
                $ids[$conversation->id] = (string) $conversation->last_activity_at;
            }
        }
        arsort($ids);

        return array_keys($ids);
    }

    /**
     * The conversations the user starred (a subquery).
     */
    protected function starredIds()
    {
        return \DB::table('conversation_folder')->join('folders', 'folders.id', '=', 'conversation_folder.folder_id')
            ->where('folders.type', Folder::TYPE_STARRED)->where('folders.user_id', $this->user->id)->select('conversation_folder.conversation_id');
    }

    /**
     * List rows of these conversations, those the user may see, in the order given.
     */
    public function rows(array $ids)
    {
        if (!$ids) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $conversations = $this->visible(Conversation::whereIn('id', $chunk))->with('customer')->get();
            if ($conversations->isEmpty()) {
                continue;
            }
            $unread = array_flip(ConversationRead::unreadIds($conversations, $this->user));
            $indirect = \DB::table('conversation_folder')->whereIn('conversation_id', $conversations->pluck('id'))->get(['conversation_id', 'folder_id'])->groupBy('conversation_id');
            foreach ($conversations as $conversation) {
                $rows[] = $this->row($conversation, isset($unread[$conversation->id]), $indirect[$conversation->id] ?? collect());
            }
        }

        $order = array_flip(array_values($ids));
        usort($rows, fn ($a, $b) => $order[$a['id']] <=> $order[$b['id']]);

        return $rows;
    }

    protected function row(Conversation $conversation, $unread, $indirect)
    {
        $mine = $conversation->user_id == $this->user->id;
        $open = in_array($conversation->status, [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING]) && $conversation->state == Conversation::STATE_PUBLISHED;
        $folders = $this->folderKeys($conversation, $mine && $open, $indirect);
        $starred = in_array($this->starredFolderId($conversation->mailbox_id), $folders, true);
        $customer = $conversation->customer;
        $name = $customer ? $customer->getFullName(true) : (string) $conversation->customer_email;
        $date = fn ($value) => $value ? \Carbon\Carbon::parse($value)->getTimestamp() : null;

        return [
            'id'            => $conversation->id,
            'number'        => $conversation->number,
            'mailbox_id'    => $conversation->mailbox_id,
            'folders'       => $folders,
            'status'        => (int) $conversation->status,
            'state'         => (int) $conversation->state,
            'user_id'       => $conversation->user_id ? (int) $conversation->user_id : null,
            'customer'      => ['id' => $conversation->customer_id, 'name' => $name, 'email' => (string) $conversation->customer_email],
            'subject'       => (string) $conversation->getSubject(),
            'preview'       => (string) $conversation->preview,
            'unread'        => (bool) $unread,
            'starred'       => $starred,
            'channel'       => $conversation->channel ? (int) $conversation->channel : null,
            'threads'       => (int) $conversation->threads_count,
            'attachments'   => (bool) $conversation->has_attachments,
            'last_activity' => $date($conversation->last_activity_at),
            'last_reply'    => $date($conversation->last_reply_at),
            'closed'        => $date($conversation->closed_at),
            'updated'       => $date($conversation->updated_at),
            'created'       => $date($conversation->created_at),
            // Kept whatever its age: unread, theirs and open, or starred.
            'keep'          => $unread || ($mine && $open) || $starred,
        ];
    }

    /**
     * The folders a row is in, for this user: its own folder (not Assigned when it's theirs: Mine
     * then), Mine, and the folders it's in besides (Starred, Drafts) that are theirs to see.
     */
    protected function folderKeys(Conversation $conversation, $mine_open, $indirect)
    {
        $folders = $this->mailboxFolders($conversation->mailbox_id);
        $keys = [];
        $main = $folders['by_id'][$conversation->folder_id] ?? null;
        if ($main && !($main->type == Folder::TYPE_ASSIGNED && $conversation->user_id == $this->user->id)) {
            $keys[] = (int) $main->id;
        }
        if ($mine_open && isset($folders['mine'])) {
            $keys[] = (int) $folders['mine']->id;
        }
        foreach ($indirect as $link) {
            $folder = $folders['by_id'][$link->folder_id] ?? null;
            if ($folder && (!$folder->user_id || $folder->user_id == $this->user->id)) {
                $keys[] = (int) $folder->id;
            }
        }

        return array_values(array_unique($keys));
    }

    protected function starredFolderId($mailbox_id)
    {
        return isset($this->mailboxFolders($mailbox_id)['starred']) ? (int) $this->mailboxFolders($mailbox_id)['starred']->id : null;
    }

    protected function mailboxFolders($mailbox_id)
    {
        if (!isset($this->folders[$mailbox_id])) {
            $folders = Folder::where('mailbox_id', $mailbox_id)->where(function ($query) {
                $query->whereNull('user_id')->orWhere('user_id', $this->user->id);
            })->get();
            $this->folders[$mailbox_id] = [
                'by_id'   => $folders->keyBy('id')->all(),
                'mine'    => $folders->first(fn ($folder) => $folder->type == Folder::TYPE_MINE),
                'starred' => $folders->first(fn ($folder) => $folder->type == Folder::TYPE_STARRED),
            ];
        }

        return $this->folders[$mailbox_id];
    }

    /**
     * Folder counts as the sidebar shows them: [folder ID => [count, active]].
     */
    public function folderCounts()
    {
        $counts = [];
        foreach ($this->mailboxIds() as $mailbox_id) {
            $folders = collect($this->mailboxFolders($mailbox_id)['by_id']);
            foreach ($folders as $folder) {
                $counts[$folder->id] = [(int) $folder->getCount($folders), (int) $folder->getActiveCount($folders)];
            }
        }

        return $counts;
    }

    /**
     * Only conversations the user may see: their mailboxes; only theirs when they see only assigned.
     */
    protected function visible($query)
    {
        $query->whereIn('mailbox_id', $this->mailboxIds() ?: [0]);
        if ($this->user->canSeeOnlyAssignedConversations()) {
            $query->where('user_id', $this->user->id);
        }

        return $query;
    }

    protected function mailboxIds()
    {
        return array_map('intval', $this->user->mailboxesIdsCanView());
    }
}
