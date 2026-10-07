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
