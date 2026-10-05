<?php

namespace App\Livewire;

use App\Conversation;
use App\Folder;
use App\Misc\AllMailboxes;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * For the open conversation's components (its column, toolbar and customer):
 * another conversation opens in them in place (conversation-open, sent by
 * public/js/conversations.js when a row of the list is clicked), without
 * loading the page.
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
     * The folder a conversation is shown in: the one it was opened from, if it's there.
     */
    public static function openedFolderId(Conversation $conversation, $folder_id)
    {
        $user = auth()->user();
        if ((int) $folder_id < 0 && AllMailboxes::isAvailable($user)) {
            return (int) $folder_id;
        }
        $folder = $folder_id ? $conversation->mailbox->folders()->where('folders.id', $folder_id)->first() : null;
        if ($folder && $conversation->isInFolderAllowed($folder)) {
            return $folder->id;
        }

        return $conversation->folder_id;
    }

    protected function openedFolder()
    {
        if ((int) $this->folder_id < 0) {
            return AllMailboxes::folder(auth()->user(), (int) $this->folder_id);
        }

        return Folder::find($this->folder_id);
    }
}
