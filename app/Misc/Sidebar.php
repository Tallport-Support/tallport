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
     * Heroicons of folder types (outline set).
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
     * The mailbox and folder a page is in (All Mailboxes: its ID), or nulls.
     */
    public static function current(array $view_data)
    {
        $mailbox = $view_data['mailbox'] ?? null;
        $folder = $view_data['folder'] ?? null;
        if ($folder && $folder->id < 0) {
            return [AllMailboxes::MAILBOX_ID, $folder->id];
        }

        return [$mailbox instanceof Mailbox ? $mailbox->id : null, $folder ? $folder->id : null];
    }
}
