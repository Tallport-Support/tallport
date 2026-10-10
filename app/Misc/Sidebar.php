<?php

namespace App\Misc;

use App\Folder;
use App\Mailbox;
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
        $is_settings = (bool) preg_match('#^(settings|mailboxes$|mailboxes\.(create|update|connection|permissions|auto_reply|telegram|nostr|customapp|ai)|users|modules|logs|system)#', $route);

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
    public static function visibleFolders($folders, $current_folder_id = null)
    {
        return $folders->filter(function ($folder) use ($current_folder_id) {
            if ($folder->id == $current_folder_id) {
                return true;
            }
            if ($folder->type == Folder::TYPE_DELETED || $folder->type == Folder::TYPE_DRAFTS) {
                return (bool) $folder->total_count;
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
     * The folder a conversation is shown in: its own (Mine when it's the user's),
     * or All Mailboxes' of that kind when the user works there.
     */
    public static function conversationFolder(\App\Conversation $conversation, ?User $user = null)
    {
        $user = $user ?: auth()->user();
        $folder = null;
        if ($user && $conversation->user_id == $user->id) {
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
