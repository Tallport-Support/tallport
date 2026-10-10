<?php

namespace App\Livewire;

use App\Conversation;
use App\Folder;
use App\Misc\AllMailboxes;
use App\Misc\ConversationListQuery;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * For the open conversation's components (its column, toolbar and customer):
 * another conversation opens in them in place (conversation-open, sent by
 * public/js/conversations.js when a row of the list is clicked), or another
 * folder's (folder-open, public/js/tallport.js: the conversation it opens at),
 * without loading the page.
 */
trait ConversationOpens
{
    #[Locked]
    public $conversation_id;

    /**
     * The folder it was opened from.
     */
    #[Locked]
    public $folder_id;

    #[On('conversation-open')]
    public function openConversation($id, $folder_id = null)
    {
        $this->switchTo($id, $folder_id);
    }

    #[On('folder-open')]
    public function openFolder($folder_id, $conversation_id = null)
    {
        $this->switchToFolder($folder_id, $conversation_id);
    }

    /**
     * Shows the conversation a folder opens at (or the one given); the conversation,
     * or null when there's none or the user can't see the folder.
     */
    protected function switchToFolder($folder_id, $conversation_id = null)
    {
        $folder = self::findFolder($folder_id);
        if (!$folder) {
            $this->skipRender();

            return null;
        }
        // The user works in its mailbox now (App\Misc\Sidebar).
        \App\Misc\Sidebar::select($folder);
        $conversation_id = $conversation_id ?: app(ConversationListQuery::class)->folderConversationId($folder, auth()->user());
        if (!$conversation_id) {
            $this->skipRender();

            return null;
        }

        return $this->switchTo($conversation_id, $folder->id);
    }

    /**
     * A folder the user can see (All Mailboxes' too), or null.
     */
    public static function findFolder($folder_id)
    {
        $user = auth()->user();
        if ((int) $folder_id < 0) {
            return AllMailboxes::isAvailable($user) ? AllMailboxes::folder($user, (int) $folder_id) : null;
        }
        $folder = Folder::find($folder_id);

        return $folder && $user->can('view', $folder) ? $folder : null;
    }

    /**
     * Shows another conversation; the conversation, or null when the user can't see it.
     */
    protected function switchTo($id, $folder_id)
    {
        $conversation = Conversation::find($id);
        if (!$conversation || !auth()->user()->can('view', $conversation)) {
            $this->skipRender();

            return null;
        }
        $this->conversation_id = $conversation->id;
        $this->folder_id = self::openedFolderId($conversation, $folder_id);

        return $conversation;
    }

    /**
     * The folder a conversation is shown in, in the mailbox the user works in
     * (App\Misc\Sidebar::conversationFolder()). $folder_id (where it was
     * opened from) is ignored.
     */
    public static function openedFolderId(Conversation $conversation, $folder_id)
    {
        $folder = \App\Misc\Sidebar::conversationFolder($conversation);

        return $folder ? $folder->id : $conversation->folder_id;
    }

    protected function openedFolder()
    {
        if ((int) $this->folder_id < 0) {
            return AllMailboxes::folder(auth()->user(), (int) $this->folder_id);
        }

        return Folder::find($this->folder_id);
    }
}
