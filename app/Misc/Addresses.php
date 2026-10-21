<?php

namespace App\Misc;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\User;

/**
 * The inbox's addresses: a folder, a conversation in it, a message in that.
 *
 *   /mailbox/support/drafts                 the Drafts folder of Support
 *   /mailbox/support/drafts/1042            conversation #1042, beside that folder's list
 *   /mailbox/support/drafts/1042/58231      the same, at that message
 *   /mailbox/all/unassigned/1042            the same in All Mailboxes
 *   /conversation/73/58231                  a link to share (its own folder for whoever opens it)
 *
 * Mailboxes by their short name (Mailbox::slug), folders by fixed English words (the same for
 * everyone, so a shared link means the same), conversations by their number. Older addresses with
 * IDs still work: they go to these.
 */
class Addresses
{
    const FOLDER_WORDS = [
        Folder::TYPE_UNASSIGNED => 'unassigned',
        Folder::TYPE_MINE       => 'mine',
        Folder::TYPE_STARRED    => 'starred',
        Folder::TYPE_DRAFTS     => 'drafts',
        Folder::TYPE_ASSIGNED   => 'assigned',
        Folder::TYPE_CLOSED     => 'closed',
        Folder::TYPE_DELETED    => 'deleted',
        Folder::TYPE_SPAM       => 'spam',
    ];

    /**
     * A folder's word; a folder of another kind (a module's) by its ID.
     */
    public static function folderWord(Folder $folder)
    {
        return self::FOLDER_WORDS[$folder->type] ?? (string) $folder->id;
    }

    public static function folderUrl(Folder $folder)
    {
        if ($folder->id < 0) {
            return url('/mailbox/all/'.self::folderWord($folder));
        }
        $mailbox = $folder->relationLoaded('mailbox') ? $folder->mailbox : Mailbox::find($folder->mailbox_id);

        return url('/mailbox/'.($mailbox ? $mailbox->getSlug() : $folder->mailbox_id).'/'.self::folderWord($folder));
    }

    /**
     * A conversation beside a folder's list, perhaps at a message.
     */
    public static function conversationUrl(Folder $folder, Conversation $conversation, $thread_id = null)
    {
        return self::folderUrl($folder).'/'.$conversation->number.($thread_id ? '/'.(int) $thread_id : '');
    }

    /**
     * A conversation's address beside a folder when there's one (its folder's ID, or the folder),
     * else the link to share.
     */
    public static function forConversation(Conversation $conversation, $folder = null, $thread_id = null)
    {
        if ($folder && !$folder instanceof Folder) {
            $folder = $folder < 0 ? AllMailboxes::folder(auth()->user(), $folder) : Folder::find($folder);
        }
        if ($folder && $folder->id) {
            return self::conversationUrl($folder, $conversation, $thread_id);
        }

        return route('conversations.view', ['id' => $conversation->id]).($thread_id ? '/'.(int) $thread_id : '');
    }

    /**
     * The folder an address names, for the user: a word (their own Mine and Starred), or an ID
     * (older addresses). $mailbox null: All Mailboxes.
     */
    public static function findFolder(?Mailbox $mailbox, $word, User $user)
    {
        $word = (string) $word;
        $type = array_search($word, self::FOLDER_WORDS, true);
        if ($mailbox === null) {
            $id = $type ? -$type : (int) $word;

            return AllMailboxes::folders($user)->firstWhere('id', $id);
        }
        $folders = $mailbox->getAssesibleFolders();
        if ($type) {
            return $folders->first(fn ($folder) => $folder->type == $type && (!$folder->user_id || $folder->user_id == $user->id));
        }

        return ctype_digit($word) ? $folders->firstWhere('id', (int) $word) : null;
    }

    /**
     * The mailbox an address names: its short name, a name it had, or (older addresses) its ID.
     */
    public static function findMailbox($value)
    {
        $value = (string) $value;
        if (ctype_digit($value)) {
            return Mailbox::find((int) $value);
        }

        return Mailbox::where('slug', $value)->first() ?: Mailbox::all()->first(fn ($mailbox) => in_array($value, (array) $mailbox->getMeta('old_slugs', []), true));
    }

    /**
     * A conversation by its number as shown (#1042), in a mailbox (or any, for All Mailboxes):
     * its own number with custom numbering, else its ID (Conversation::getNumberAttribute()).
     */
    public static function findConversation(?Mailbox $mailbox, $number)
    {
        return Conversation::where(config('app.custom_number') ? 'number' : 'id', (int) $number)
            ->when($mailbox, fn ($query) => $query->where('mailbox_id', $mailbox->id))->first();
    }
}
