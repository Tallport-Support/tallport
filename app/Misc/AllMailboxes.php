<?php

namespace App\Misc;

use App\Conversation;
use App\ConversationFolder;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;

/**
 * All Mailboxes: the folders of every mailbox a user has, together (for
 * users with more than one). A mailbox that doesn't exist (MAILBOX_ID) with
 * folders whose ID is minus their type.
 */
class AllMailboxes
{
    const MAILBOX_ID = -1;

    /**
     * Conversations the user replied in, by the latest reply.
     */
    const TYPE_SENT = 1000;

    const TYPES = [
        Folder::TYPE_UNASSIGNED,
        Folder::TYPE_MINE,
        Folder::TYPE_ASSIGNED,
        Folder::TYPE_STARRED,
        Folder::TYPE_DRAFTS,
        self::TYPE_SENT,
        Folder::TYPE_CLOSED,
        Folder::TYPE_SPAM,
        Folder::TYPE_DELETED,
    ];

    public static function isAvailable(?User $user = null)
    {
        $user = $user ?: auth()->user();

        return $user && count($user->mailboxesIdsCanView()) > 1;
    }

    public static function isAllMailboxes($mailbox_id)
    {
        return (int) $mailbox_id === self::MAILBOX_ID;
    }

    public static function mailbox()
    {
        $mailbox = new Mailbox();
        $mailbox->id = self::MAILBOX_ID;
        $mailbox->name = __('All Mailboxes');
        $mailbox->email = '';

        return $mailbox;
    }

    /**
     * The folders, with the conversations counted in every mailbox.
     */
    public static function folders(User $user)
    {
        $mailbox_ids = $user->mailboxesIdsCanView();
        $real = Folder::whereIn('mailbox_id', $mailbox_ids)
            ->where(function ($query) use ($user) {
                $query->whereIn('type', \Eventy::filter('mailbox.folders.public_types', Folder::$public_types))
                    ->orWhere(function ($query) use ($user) {
                        $query->whereIn('type', Folder::$personal_types)->where('user_id', $user->id);
                    });
            })
            ->get()
            ->groupBy('type');

        $folders = collect();
        foreach (self::TYPES as $type) {
            $folder = new Folder();
            $folder->id = -$type;
            $folder->type = $type;
            $folder->mailbox_id = $mailbox_ids[0] ?? null;
            $folder->user_id = in_array($type, Folder::$personal_types) ? $user->id : null;
            $folder->active_count = (int) ($real[$type] ?? collect())->sum('active_count');
            $folder->total_count = (int) ($real[$type] ?? collect())->sum('total_count');
            $folders->push($folder);
        }

        return $folders;
    }

    /**
     * A folder by its ID (minus its type).
     */
    public static function folder(User $user, $folder_id)
    {
        return self::folders($user)->firstWhere('id', (int) $folder_id);
    }

    /**
     * The conversations of a folder in every mailbox of the user.
     */
    public static function query(Folder $folder, User $user)
    {
        $mailbox_ids = $user->mailboxesIdsCanView();
        $folder_ids = function ($type, $personal = false) use ($mailbox_ids, $user) {
            $query = Folder::whereIn('mailbox_id', $mailbox_ids)->where('type', $type);
            if ($personal) {
                $query->where('user_id', $user->id);
            }

            return $query->pluck('id');
        };

        switch ($folder->type) {
            case Folder::TYPE_MINE:
                $query = Conversation::whereIn('mailbox_id', $mailbox_ids)
                    ->where('user_id', $user->id)
                    ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
                    ->where('state', Conversation::STATE_PUBLISHED);
                break;

            case Folder::TYPE_ASSIGNED:
                $query = Conversation::whereIn('folder_id', $folder_ids(Folder::TYPE_ASSIGNED))
                    ->where('user_id', '<>', $user->id)
                    ->where('state', Conversation::STATE_PUBLISHED);
                break;

            case Folder::TYPE_STARRED:
                $query = Conversation::whereIn('id', ConversationFolder::whereIn('folder_id', $folder_ids(Folder::TYPE_STARRED, true))->select('conversation_id'))
                    ->where('state', Conversation::STATE_PUBLISHED);
                break;

            case Folder::TYPE_DRAFTS:
                $query = Conversation::whereIn('id', ConversationFolder::whereIn('folder_id', $folder_ids(Folder::TYPE_DRAFTS))->select('conversation_id'));
                break;

            case Folder::TYPE_DELETED:
                $query = Conversation::whereIn('folder_id', $folder_ids(Folder::TYPE_DELETED))
                    ->where('state', Conversation::STATE_DELETED);
                break;

            case self::TYPE_SENT:
                $query = Conversation::select(['conversations.*', \DB::raw('MAX('.\DB::getTablePrefix().'threads.created_at) AS last_user_reply_at')])
                    ->join('threads', function ($join) use ($user) {
                        $join->on('conversations.id', '=', 'threads.conversation_id')
                            ->where('threads.type', Thread::TYPE_MESSAGE)
                            ->where('threads.created_by_user_id', $user->id);
                    })
                    ->whereIn('conversations.mailbox_id', $mailbox_ids)
                    ->where('conversations.state', Conversation::STATE_PUBLISHED)
                    ->groupBy('conversations.id');
                break;

            default:
                $query = Conversation::whereIn('folder_id', $folder_ids($folder->type))
                    ->where('state', Conversation::STATE_PUBLISHED);
                break;
        }

        // Users who see only conversations assigned to them.
        if ($user->canSeeOnlyAssignedConversations()) {
            if ($folder->type == Folder::TYPE_DRAFTS) {
                $query->where(function ($query) use ($user) {
                    $query->where('conversations.user_id', $user->id)->orWhere('conversations.created_by_user_id', $user->id);
                });
            } else {
                $query->where('conversations.user_id', $user->id);
            }
        }

        return $query;
    }

    /**
     * Delete the conversations in Spam or Deleted of every mailbox.
     */
    public static function emptyFolder(User $user, $folder_id)
    {
        $type = -(int) $folder_id;
        if (!in_array($type, [Folder::TYPE_SPAM, Folder::TYPE_DELETED])) {
            return false;
        }
        $folders = Folder::whereIn('mailbox_id', $user->mailboxesIdsCanView())->where('type', $type)->get();
        do {
            $query = Conversation::whereIn('folder_id', $folders->pluck('id'));
            // Users who see only their conversations delete only those.
            if (!$user->isAdmin() && $user->canSeeOnlyAssignedConversations()) {
                $query->where('user_id', $user->id);
            }
            $conversation_ids = $query->limit(\Helper::IN_LIMIT)->pluck('id')->toArray();
            Conversation::deleteConversationsForever($conversation_ids);
        } while (count($conversation_ids));

        foreach ($folders as $folder) {
            Conversation::clearStarredByUserCache($user->id, $folder->mailbox_id);
            if ($folder->mailbox) {
                $folder->mailbox->updateFoldersCounters();
            }
        }

        return true;
    }

    /**
     * The mailbox and folder pages, links and lists know All Mailboxes.
     */
    public static function listen()
    {
        \Eventy::addFilter('mailbox.url', function ($url, $mailbox) {
            return self::isAllMailboxes($mailbox->id) ? route('mailboxes.all') : $url;
        }, 20, 2);

        \Eventy::addFilter('folder.url', function ($url, $mailbox_id, $folder) {
            return self::isAllMailboxes($mailbox_id) ? route('mailboxes.all', ['folder_id' => $folder->id]) : $url;
        }, 20, 3);

        \Eventy::addFilter('folder.type_name', function ($name, $folder) {
            return $folder->type == self::TYPE_SENT ? 'Sent' : $name;
        }, 20, 2);

        \Eventy::addFilter('folder.type_icon', function ($icon, $folder) {
            return $folder->type == self::TYPE_SENT ? 'send' : $icon;
        }, 20, 2);

        \Eventy::addFilter('folder.conversations_order_by', function ($order_by, $type) {
            return $type == self::TYPE_SENT ? [['last_user_reply_at' => 'desc']] : $order_by;
        }, 20, 2);

        \Eventy::addFilter('mailbox.show_buttons', function ($show, $mailbox) {
            return self::isAllMailboxes($mailbox->id) ? false : $show;
        }, 20, 2);

        // Next pages of a list.
        \Eventy::addFilter('conversations.ajax_pagination_folder', function ($folder, $request, $response, $user) {
            return self::isAllMailboxes($request->mailbox_id) ? self::folder($user, $request->folder_id) : $folder;
        }, 20, 4);
        \Eventy::addFilter('folder.conversations_query', function ($query, $folder, $user_id) {
            if ($folder->id < 0) {
                return self::query($folder, User::find($user_id));
            }

            return $query;
        }, 20, 3);

    }
}
