<?php

namespace App\Inbox;

use App\Conversation;
use App\ConversationRead;
use App\Folder;
use App\Misc\AllMailboxes;
use App\User;

/**
 * The browser's local copy of the inbox's list rows (public/js/inbox.js): what a user may see, as
 * a list row shows it. Kept in the browser: their unread conversations, those assigned to them and
 * not closed, the others not closed, those they starred, and everything from the last few days, each
 * with a ceiling. A first sync sends the
 * folder in view first, then the rest in pages; after it, the change log (App\Inbox\Changes) says
 * which rows to send again.
 */
class Sync
{
    /**
     * What's kept: unread, theirs and open, starred, recent (days of activity), open, each with its ceiling.
     */
    const RECENT_DAYS = 7;
    const MAX_UNREAD = 1000;
    const MAX_MINE = 1000;
    const MAX_STARRED = 1000;
    const MAX_RECENT = 2000;
    const MAX_OPEN = 2000;

    /**
     * Rows a page of the first sync, and changed conversations an incremental one.
     */
    const PAGE = 500;
    const CHANGES = 2000;

    /**
     * The rows' format: a browser's copy in another starts over.
     */
    const FORMAT = 3;

    /**
     * Columns lists are ordered by, as rows have them (public/js/inbox.js draws lists in that order).
     */
    const ORDER_COLUMNS = [
        'status'           => 'status',
        'last_reply_at'    => 'last_reply',
        'last_activity_at' => 'last_activity',
        'last_sent_at'     => 'last_sent',
        'updated_at'       => 'updated',
        'closed_at'        => 'closed',
        'user_updated_at'  => 'user_updated',
        'number'           => 'number',
        'subject'          => 'subject',
    ];

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
     * A conversation rendered to be fetched ahead (InboxController::pane()): nothing is asked of
     * the AI for it (a summary, a translation), as nobody opened it.
     */
    public static function prefetching()
    {
        return (bool) request()->attributes->get('inbox_prefetch');
    }

    /**
     * What the page gives the browser's copy (public/js/inbox.js), and how it draws a list row as
     * the server does (conversations_table): the time shown ("3h", in the user's language, as
     * User::dateDiffForHumans() writes it), names and labels.
     */
    public static function config(User $user)
    {
        $locale = app()->getLocale();
        $times = \Cache::rememberForever('inbox_times_'.$locale, function () {
            $short = fn ($ago) => now()->sub($ago)->diffForHumans(['short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]);
            $times = [];
            foreach (['m' => [59, 'minutes'], 'h' => [23, 'hours'], 'd' => [7, 'days']] as $unit => [$max, $name]) {
                foreach (range(1, $max) as $count) {
                    $times[$unit][$count] = $short(\Carbon\CarbonInterval::make($count.' '.$name));
                }
            }

            return $times;
        });
        $statuses = [];
        foreach (array_keys(Conversation::STATUS_TONES) as $status) {
            $statuses[$status] = [Conversation::statusCodeToName($status), Conversation::STATUS_TONES[$status]];
        }

        return [
            'key'         => self::sessionKey(),
            'user'        => $user->id,
            'install'     => substr(sha1(config('app.url').config('app.key')), 0, 12),
            'url'         => route('inbox.sync'),
            'pane_url'    => url('/inbox/pane'),
            'recent_days' => self::RECENT_DAYS,
            'locale'      => $locale,
            'timezone'    => $user->timezone ?: config('app.timezone'),
            'times'       => $times,
            'statuses'    => $statuses,
            'labels'      => [
                'just_now'      => __('Just now'),
                'unread'        => __('Unread'),
                'phone'         => __('Phone'),
                'attachments'   => __('Attachments'),
                'closed'        => __('Closed'),
                'spam'          => __('Spam'),
                'deleted'       => __('Deleted'),
                'drafts'        => __('Drafts'),
            ],
        ];
    }

    /**
     * What the user may see, in short: when it changes (mailbox access, a role, "only assigned"),
     * the browser's copy starts over.
     */
    public function scope()
    {
        $mailbox_ids = $this->mailboxIds();
        sort($mailbox_ids);

        return substr(sha1(json_encode([self::FORMAT, $mailbox_ids, $this->user->isAdmin(), $this->user->canSeeOnlyAssignedConversations()])), 0, 16);
    }

    /**
     * A page of the first sync: the folder in view first (page 0), then what's kept, newest first.
     */
    public function full($page, $folder_id = null)
    {
        $cursor = Changes::latest();
        $ids = [];
        $folder = $page == 0 && $folder_id ? ($folder_id < 0 ? AllMailboxes::folder($this->user, $folder_id) : Folder::find($folder_id)) : null;
        if ($folder && ($folder->id < 0 || in_array($folder->mailbox_id, $this->mailboxIds()))) {
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
            'lists'   => $this->lists(),
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
            'lists'   => $this->lists(),
            'more'    => $changes->count() >= self::CHANGES,
        ];
    }

    /**
     * The IDs kept in the browser, newest activity first: unread, theirs and open, starred, open, recent.
     */
    public function keptIds()
    {
        $ids = [];
        foreach ([
            [fn ($query) => ConversationRead::whereUnread($query, $this->user), self::MAX_UNREAD],
            [fn ($query) => $query->where('user_id', $this->user->id)->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])->where('state', Conversation::STATE_PUBLISHED), self::MAX_MINE],
            [fn ($query) => $query->whereIn('id', $this->starredIds()), self::MAX_STARRED],
            // Open in a shared folder (Unassigned, Assigned): their lists draw from the copy too.
            [fn ($query) => $query->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])->where('state', Conversation::STATE_PUBLISHED), self::MAX_OPEN],
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
            $conversations = $this->visible(Conversation::whereIn('id', $chunk))->with(['customer', 'user'])->get();
            if ($conversations->isEmpty()) {
                continue;
            }
            $unread = array_flip(ConversationRead::unreadIds($conversations, $this->user));
            $indirect = \DB::table('conversation_folder')->whereIn('conversation_id', $conversations->pluck('id'))->get(['conversation_id', 'folder_id'])->groupBy('conversation_id');
            $all = $this->allMailboxesKeys($conversations->pluck('id')->all());
            $panes = self::paneVersions($conversations);
            // As the list shows them: in the user's language.
            $subjects = \App\Ai\Translations::subjects($conversations, $this->user);
            $previews = \App\Ai\Translations::previews($conversations, $this->user);
            foreach ($conversations as $conversation) {
                $row = $this->row($conversation, isset($unread[$conversation->id]), $indirect[$conversation->id] ?? collect());
                $row['folders'] = array_merge($row['folders'], $all[$conversation->id] ?? []);
                $row['translated_subject'] = $subjects[$conversation->id] ?? null;
                $one_liner = \App\Ai\Summaries::getAny($conversation, \App\Ai\Settings::language($conversation->mailbox_cached, $this->user))['one_liner'] ?? '';
                $row['list_preview'] = (string) ($one_liner ?: ($previews[$conversation->id] ?? $conversation->preview));
                $row['pane'] = $panes[$conversation->id] ?? null;
                $rows[] = $row;
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
        $assignee = $conversation->user_id && $conversation->user_id != $this->user->id ? $conversation->user : null;
        $name = $customer ? $customer->getFullName(true) : (string) $conversation->customer_email;
        $date = fn ($value) => $value ? \Carbon\Carbon::parse($value)->getTimestamp() : null;

        return [
            'id'            => $conversation->id,
            'number'        => $conversation->number,
            'mailbox_id'    => $conversation->mailbox_id,
            // Across mailboxes: its mailbox's color and name (conversations_table).
            'mark'          => [$conversation->mailbox_cached->accent ?: 'blue', __(':name mailbox', ['name' => $conversation->mailbox_cached->name])],
            'folders'       => $folders,
            'status'        => (int) $conversation->status,
            'state'         => (int) $conversation->state,
            'user_id'       => $conversation->user_id ? (int) $conversation->user_id : null,
            'assignee'      => $assignee ? $assignee->getFullName() : null,
            'type'          => (int) $conversation->type,
            'customer'      => ['id' => $conversation->customer_id, 'name' => $name, 'email' => (string) $conversation->customer_email],
            'subject'       => (string) $conversation->getSubject(),
            'preview'       => (string) $conversation->preview,
            'unread'        => (bool) $unread,
            'starred'       => $starred,
            'channel'       => $conversation->channel ? (int) $conversation->channel : null,
            'channel_name'  => $conversation->hasChannel() ? ($conversation->getChannelName() ?: __('Chat')) : null,
            'threads'       => (int) $conversation->threads_count,
            'attachments'   => (bool) $conversation->has_attachments,
            'last_activity' => $date($conversation->last_activity_at),
            'last_reply'    => $date($conversation->last_reply_at),
            'closed'        => $date($conversation->closed_at),
            'updated'       => $date($conversation->updated_at),
            'user_updated'  => $date($conversation->user_updated_at),
            'last_sent'     => $date($conversation->last_sent_at),
            'created'       => $date($conversation->created_at),
            // Kept whatever its age: unread, open, or starred.
            'keep'          => $unread || $open || $starred,
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

    /**
     * What each conversation's pane shows, in short: when it changes (a message, an edit, a
     * translation, its summary, its status), a snapshot of the pane in the browser
     * (public/js/inbox.js) is out of date. [conversation ID => version]
     */
    public static function paneVersions($conversations)
    {
        $conversations = collect($conversations);
        if ($conversations->isEmpty()) {
            return [];
        }
        $threads = \DB::table('threads')->whereIn('conversation_id', $conversations->pluck('id'))->groupBy('conversation_id')
            ->selectRaw('conversation_id, count(*) as n, max(updated_at) as updated, max(ai_assistant_updated_at) as ai')->get()->keyBy('conversation_id');
        $versions = [];
        foreach ($conversations as $conversation) {
            $thread = $threads[$conversation->id] ?? null;
            $versions[$conversation->id] = substr(sha1(json_encode([
                (string) $conversation->updated_at, (string) $conversation->ai_assistant_updated_at,
                $thread ? [(int) $thread->n, (string) $thread->updated, (string) $thread->ai] : null,
            ])), 0, 12);
        }

        return $versions;
    }

    /**
     * All Mailboxes' folders these conversations are in (minus their type), by All Mailboxes' own
     * queries: [conversation ID => [folder ID, ...]]. Sent is left to the server (its order is
     * by the user's own replies).
     */
    protected function allMailboxesKeys(array $ids)
    {
        $keys = [];
        if (!AllMailboxes::isAvailable($this->user)) {
            return $keys;
        }
        foreach (AllMailboxes::folders($this->user) as $folder) {
            if ($folder->type == AllMailboxes::TYPE_SENT) {
                continue;
            }
            foreach (AllMailboxes::query($folder, $this->user)->whereIn('conversations.id', $ids)->pluck('conversations.id') as $id) {
                $keys[$id][] = (int) $folder->id;
            }
        }

        return $keys;
    }

    /**
     * How the browser draws each folder's list from its copy, as the server would: the order
     * (row fields), the time shown, and how many it has. Only folders whose order rows have.
     */
    public function lists()
    {
        $folders = collect();
        foreach ($this->mailboxIds() as $mailbox_id) {
            $folders = $folders->merge($this->mailboxFolders($mailbox_id)['by_id']);
        }
        if (AllMailboxes::isAvailable($this->user)) {
            $folders = $folders->merge(AllMailboxes::folders($this->user)->where('type', '!=', AllMailboxes::TYPE_SENT));
        }
        $lists = [];
        $totals = $this->totals($folders);
        foreach ($folders as $folder) {
            $order = [];
            foreach ($folder->getOrderByArray() as $block) {
                foreach ($block as $column => $direction) {
                    $order[] = [self::ORDER_COLUMNS[$column] ?? null, $direction];
                }
            }
            if (!$order || in_array(null, array_column($order, 0), true)) {
                continue;
            }
            $sorting = Conversation::getConvTableSorting(null, $folder);
            $time = Conversation::SORT_COLUMNS[$sorting['sort_by']] ?? $folder->getWaitingSinceField();
            $lists[$folder->id] = [
                'type'  => (int) $folder->type,
                'order' => $order,
                'time'  => self::ORDER_COLUMNS[$time] ?? 'last_reply',
                'total' => $totals[$folder->id] ?? 0,
                // As the list's heading says it.
                'count' => trans_choice(':count conversation|:count conversations', $totals[$folder->id] ?? 0),
            ];
        }

        return $lists;
    }

    /**
     * How many conversations each folder's list has (as its own query counts them), until the
     * next change.
     */
    protected function totals($folders)
    {
        return \Cache::remember('inbox_totals_'.$this->user->id.'_'.Changes::latest().'_'.$this->scope(), 60, function () use ($folders) {
            $totals = [];
            foreach ($folders as $folder) {
                $query = $folder->id < 0 ? AllMailboxes::query($folder, $this->user) : Conversation::getQueryByFolder($folder, $this->user->id);
                $totals[$folder->id] = (int) $query->count();
            }

            return $totals;
        });
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
