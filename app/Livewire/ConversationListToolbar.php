<?php

namespace App\Livewire;

use App\Misc\ConversationListQuery;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The list pane's toolbar beside an open conversation: the folder, its mailbox and
 * how many conversations it holds (mailboxes/partials/list_toolbar); it follows a
 * folder opened in place (folder-open).
 */
class ConversationListToolbar extends Component
{
    #[Locked]
    public $folder_id;

    public function mount($folder)
    {
        $this->folder_id = $folder->id;
    }

    #[On('folder-open')]
    public function openFolder($folder_id)
    {
        $folder = ConversationOpens::findFolder($folder_id);
        if (!$folder) {
            $this->skipRender();

            return;
        }
        $this->folder_id = $folder->id;
    }

    public function render()
    {
        $folder = ConversationOpens::findFolder($this->folder_id);
        abort_unless($folder, 403);
        $list = app(ConversationListQuery::class)->folderList($folder, auth()->user());

        return view('livewire.conversation-list-toolbar', [
            'folder'        => $folder,
            'mailbox'       => $list['mailbox'],
            'conversations' => $list['conversations'],
        ]);
    }
}
