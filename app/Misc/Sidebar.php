<?php

namespace App\Misc;

use App\Folder;
use App\Mailbox;
use App\Conversation;
use App\ConversationFolder;
use App\ConversationRead;
use App\Thread;
use App\User;

/**
 * The app's sidebar: All Mailboxes and the user's mailboxes with their
 * folders, then the rest of the app (partials/app_sidebar).
 */
class Sidebar
{
    /**
     * Whether the page is one of the settings (the app's, a mailbox's, users',
     * the account's, Manage): the sidebar then lists the settings instead of the
     * mailboxes (partials/app_sidebar_settings). Modules' pages may join in.
     */
    public static function isSettings()
    {
        $route = (string) \Route::currentRouteName();
        $is_settings = (bool) preg_match('#^(settings|mailboxes$|mailboxes\.(create|update|connection|permissions|auto_reply|telegram|nostr|matrix|customapp|ai|documentation)|users|own\.|modules|logs|system)#', $route);

        return (bool) \Eventy::filter('sidebar.is_settings', $is_settings, $route);
    }

    /**
     * What Settings' search finds a page by (partials/app_sidebar_settings): the
     * labels and section titles in its views, in the user's language, read from
     * the templates so they follow the pages.
     */
    public static function settingsKeywords(...$views)
    {
        $keywords = [];
        foreach ($views as $view) {
            $path = resource_path('views/'.$view.'.blade.php');
            if (!is_file($path)) {
                continue;
            }
            // Labels and titles given to fruit fields and sections, and row labels in spans.
            preg_match_all("/(?::label|:title)=\"__\('((?:[^'\\\\]|\\\\.)+)'\)|<span>\{\{ __\('((?:[^'\\\\]|\\\\.)+)'\) \}\}<\/span>/", file_get_contents($path), $matches);
            foreach (array_filter(array_merge($matches[1], $matches[2])) as $label) {
                $keywords[] = __(stripslashes($label));
            }
        }

        return mb_strtolower(implode(' · ', array_unique($keywords)));
    }

    /**
     * Lucide icons of folder types.
     */
    const FOLDER_ICONS = [
        Folder::TYPE_UNASSIGNED => 'inbox',
        Folder::TYPE_MINE       => 'user',
        Folder::TYPE_ASSIGNED   => 'users',
        Folder::TYPE_STARRED    => 'star',
        Folder::TYPE_DRAFTS     => 'file-text',
        Folder::TYPE_CLOSED     => 'circle-check',
        Folder::TYPE_SPAM       => 'ban',
        Folder::TYPE_DELETED    => 'trash-2',
        AllMailboxes::TYPE_SENT => 'send',
    ];

    public static function folderIcon(Folder $folder)
    {
        return 'icon.'.(self::FOLDER_ICONS[$folder->type] ?? 'folder');
    }

    /**
     * The folders shown: Deleted only with something in it (or when open),
     * Drafts only with drafts.
     */
    public static function visibleFolders($folders, $current_folder_id = null, $unread_counts = [])
    {
        return $folders->filter(function ($folder) use ($current_folder_id, $unread_counts) {
            if ($folder->id == $current_folder_id) {
                return true;
            }
            if ($folder->type == Folder::TYPE_DELETED || $folder->type == Folder::TYPE_DRAFTS) {
                return (bool) ($folder->total_count || ($unread_counts[$folder->id] ?? 0));
            }

            return true;
        });
    }

    /**
     * The number next to a folder: active conversations (all in Spam).
     */
    public static function count(Folder $folder, $folders)
    {
        return $folder->type == Folder::TYPE_SPAM ? (int) $folder->total_count : (int) $folder->getCount($folders);
    }

    /**
     * Unread message counts for the user's folders and mailbox headings.
     */
    public static function unreadCounts(User $user)
    {
        $mailbox_ids = $user->mailboxesIdsCanView();
        if (!$mailbox_ids) {
            return ['folders' => [], 'mailboxes' => [], 'for_you' => ['folders' => [], 'mailboxes' => []]];
        }

        $folders = Folder::whereIn('mailbox_id', $mailbox_ids)
            ->where(function ($query) use ($user) {
                $query->whereIn('type', \Eventy::filter('mailbox.folders.public_types', Folder::$public_types))
                    ->orWhere(function ($query) use ($user) {
                        $query->whereIn('type', Folder::$personal_types)->where('user_id', $user->id);
                    });
            })->get();
        $folder_by_id = $folders->keyBy('id');
        $folder_by_mailbox_type = [];
        $counts = [];
        $mailbox_counts = array_fill_keys($mailbox_ids, 0);
        foreach ($folders as $folder) {
            $folder_by_mailbox_type[$folder->mailbox_id][$folder->type] = $folder->id;
            $counts[$folder->id] = 0;
        }

        $unread = ConversationRead::unreadMessageCounts($user)->keyBy('id');
        foreach ($unread as $conversation) {
            $count = (int) $conversation->unread_count;
            $mailbox_counts[$conversation->mailbox_id] += $count;
            $folder = $folder_by_id->get($conversation->folder_id);
            if ($folder && (($folder->type == Folder::TYPE_DELETED && $conversation->state == Conversation::STATE_DELETED)
                || ($folder->type != Folder::TYPE_DELETED && $conversation->state == Conversation::STATE_PUBLISHED))
                && ($folder->type != Folder::TYPE_ASSIGNED || $conversation->user_id != $user->id)
                && !in_array($folder->type, [Folder::TYPE_MINE, Folder::TYPE_STARRED, Folder::TYPE_DRAFTS])
            ) {
                $counts[$folder->id] += $count;
            }
            if ($conversation->state == Conversation::STATE_PUBLISHED && $conversation->user_id == $user->id
                && in_array($conversation->status, [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
            ) {
                $mine_id = $folder_by_mailbox_type[$conversation->mailbox_id][Folder::TYPE_MINE] ?? null;
                if ($mine_id) {
                    $counts[$mine_id] += $count;
                }
            }
        }

        $indirect_ids = $folders->whereIn('type', [Folder::TYPE_STARRED, Folder::TYPE_DRAFTS])->pluck('id')->all();
        $all_starred = 0;
        foreach (array_chunk($unread->keys()->all(), 500) as $conversation_ids) {
            if ($indirect_ids) {
                foreach (ConversationFolder::whereIn('folder_id', $indirect_ids)->whereIn('conversation_id', $conversation_ids)->get() as $link) {
                    $conversation = $unread->get($link->conversation_id);
                    if ($conversation) {
                        $counts[$link->folder_id] += (int) $conversation->unread_count;
                        if ($folder_by_id->get($link->folder_id)->type == Folder::TYPE_STARRED && $conversation->state == Conversation::STATE_PUBLISHED) {
                            $all_starred += (int) $conversation->unread_count;
                        }
                    }
                }
            }
        }

        if (AllMailboxes::isAvailable($user)) {
            $sent = 0;
            foreach (array_chunk($unread->keys()->all(), 500) as $conversation_ids) {
                $sent_ids = Thread::whereIn('conversation_id', $conversation_ids)
                    ->where('type', Thread::TYPE_MESSAGE)->where('created_by_user_id', $user->id)
                    ->distinct()->pluck('conversation_id');
                foreach ($sent_ids as $conversation_id) {
                    if ($unread[$conversation_id]->state == Conversation::STATE_PUBLISHED) {
                        $sent += (int) $unread[$conversation_id]->unread_count;
                    }
                }
            }
            foreach (AllMailboxes::TYPES as $type) {
                if ($type == AllMailboxes::TYPE_SENT) {
                    $counts[-$type] = $sent;
                } elseif ($type == Folder::TYPE_STARRED) {
                    $counts[-$type] = $all_starred;
                } else {
                    $counts[-$type] = $folders->where('type', $type)->sum(fn ($folder) => $counts[$folder->id]);
                }
            }
        }

        return ['folders' => $counts, 'mailboxes' => $mailbox_counts, 'for_you' => self::forYou($user, $folders, $mailbox_ids)];
    }

    /**
     * The folders and mailboxes that hold an active conversation for the user (assigned to them
     * or to nobody): with nothing unread, the sidebar shows them a dot. [folders, mailboxes], as
     * id => true.
     */
    protected static function forYou(User $user, $folders, array $mailbox_ids)
    {
        $active = Conversation::whereIn('mailbox_id', $mailbox_ids)
            ->where('state', Conversation::STATE_PUBLISHED)->where('status', Conversation::STATUS_ACTIVE);
        if ($user->canSeeOnlyAssignedConversations()) {
            $active->where('user_id', $user->id);
        } else {
            $active->where(function ($query) use ($user) {
                $query->whereNull('user_id')->orWhere('user_id', 0)->orWhere('user_id', $user->id);
            });
        }

        $folder_by_id = $folders->keyBy('id');
        $result = ['folders' => [], 'mailboxes' => []];
        foreach ((clone $active)->select('mailbox_id', 'folder_id', 'user_id')->distinct()->get() as $row) {
            $result['mailboxes'][$row->mailbox_id] = true;
            // Assigned lists others' conversations; the user's are in Mine (by assignee, not folder).
            $folder = $folder_by_id->get($row->folder_id);
            if ($folder && $folder->type != Folder::TYPE_ASSIGNED) {
                $result['folders'][$folder->id] = true;
            }
            if ($row->user_id == $user->id) {
                $mine = $folders->first(fn ($folder) => $folder->mailbox_id == $row->mailbox_id && $folder->type == Folder::TYPE_MINE);
                if ($mine) {
                    $result['folders'][$mine->id] = true;
                }
            }
        }
        // Starred: the user's own list of conversations.
        $starred_ids = $folders->where('type', Folder::TYPE_STARRED)->pluck('id')->all();
        if ($starred_ids) {
            $starred = ConversationFolder::whereIn('folder_id', $starred_ids)
                ->whereIn('conversation_id', (clone $active)->select('id'))->distinct()->pluck('folder_id');
            foreach ($starred as $folder_id) {
                $result['folders'][$folder_id] = true;
            }
        }

        if (AllMailboxes::isAvailable($user)) {
            foreach (AllMailboxes::TYPES as $type) {
                if ($type == AllMailboxes::TYPE_SENT) {
                    $sent = Thread::where('type', Thread::TYPE_MESSAGE)->where('created_by_user_id', $user->id)
                        ->whereIn('conversation_id', (clone $active)->select('id'))->exists();
                    if ($sent) {
                        $result['folders'][-$type] = true;
                    }
                } elseif ($folders->where('type', $type)->contains(fn ($folder) => !empty($result['folders'][$folder->id]))) {
                    $result['folders'][-$type] = true;
                }
            }
        }

        return $result;
    }

    /**
     * The mailboxes with their folders: [[mailbox, folders], ...].
     */
    public static function mailboxes(User $user)
    {
        $result = [];
        foreach (\Eventy::filter('menu.mailboxes', $user->mailboxesCanView(true)) as $mailbox) {
            $result[] = [$mailbox, $mailbox->getAssesibleFolders()];
        }

        return $result;
    }

    /**
     * The session's key for the mailbox the user works in (select()).
     */
    const SCOPE = 'sidebar_mailbox_id';

    /**
     * The user selected a folder (its page, or opened in place): its mailbox, or
     * All Mailboxes, is where they work from now on. It stays so whatever happens
     * next (opening, replying to, moving or deleting conversations, other pages)
     * until they select a folder elsewhere. Kept in the session, not in URLs.
     */
    public static function select(Folder $folder)
    {
        session()->put(self::SCOPE, $folder->id < 0 ? AllMailboxes::MAILBOX_ID : (int) $folder->mailbox_id);
    }

    /**
     * The mailbox the user works in (select()): its ID, AllMailboxes::MAILBOX_ID,
     * or null before they select a folder (or when they no longer have it).
     */
    public static function scope(?User $user = null)
    {
        $user = $user ?: auth()->user();
        $mailbox_id = (int) session()->get(self::SCOPE);
        if (!$user || !$mailbox_id) {
            return null;
        }
        if (AllMailboxes::isAllMailboxes($mailbox_id)) {
            return AllMailboxes::isAvailable($user) ? $mailbox_id : null;
        }

        return in_array($mailbox_id, $user->mailboxesIdsCanView()) ? $mailbox_id : null;
    }

    /**
     * The folder a conversation is shown in: its own (Mine when it's the user's and open,
     * as Mine lists), or All Mailboxes' of that kind when the user works there.
     */
    public static function conversationFolder(\App\Conversation $conversation, ?User $user = null)
    {
        $user = $user ?: auth()->user();
        $folder = null;
        if ($user && $conversation->user_id == $user->id && $conversation->state == \App\Conversation::STATE_PUBLISHED
            && in_array($conversation->status, [\App\Conversation::STATUS_ACTIVE, \App\Conversation::STATUS_PENDING])
        ) {
            $folder = $conversation->mailbox->folders()->where('type', Folder::TYPE_MINE)->where('user_id', $user->id)->first();
        }
        $folder = $folder ?: $conversation->folder;
        if ($folder && AllMailboxes::isAllMailboxes(self::scope($user)) && in_array($folder->type, AllMailboxes::TYPES)) {
            return AllMailboxes::folder($user, -$folder->type) ?: $folder;
        }

        return $folder;
    }

    /**
     * Where the user goes back to (after deleting a conversation, for example): this
     * folder (null: the mailbox's first) in the mailbox they work in. In All Mailboxes,
     * its folder of that kind; in another mailbox, that mailbox.
     */
    public static function folderUrl(?Folder $folder, $mailbox_id = null)
    {
        $scope = self::scope();
        $mailbox_id = $folder ? $folder->mailbox_id : $mailbox_id;
        if (AllMailboxes::isAllMailboxes($scope)) {
            $type = $folder && in_array($folder->type, AllMailboxes::TYPES) ? $folder->type : Folder::TYPE_UNASSIGNED;

            return route('mailboxes.all', ['folder_id' => -$type]);
        }
        if ($scope && $scope != $mailbox_id) {
            return route('mailboxes.view', ['id' => $scope]);
        }

        return $folder ? $folder->url($folder->mailbox_id) : route('mailboxes.view', ['id' => $mailbox_id]);
    }

    /**
     * The mailbox and folder the sidebar shows as current (All Mailboxes: its
     * ID): the mailbox the user works in, with the page's folder if it is there;
     * before they select a folder, the page's; or nulls.
     */
    public static function current(array $view_data)
    {
        $mailbox = $view_data['mailbox'] ?? null;
        $folder = $view_data['folder'] ?? null;
        $folder_mailbox_id = $folder && $folder->id < 0 ? AllMailboxes::MAILBOX_ID : ($folder && $folder->id ? $folder->mailbox_id : ($mailbox instanceof Mailbox ? $mailbox->id : null));
        $scope = self::scope() ?: $folder_mailbox_id;

        return [$scope, $folder && $folder->id && $folder_mailbox_id == $scope ? $folder->id : null];
    }
}
