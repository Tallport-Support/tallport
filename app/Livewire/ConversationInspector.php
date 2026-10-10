<?php

namespace App\Livewire;

use App\Conversation;
use App\Http\Controllers\ConversationsController;
use Livewire\Component;

/**
 * The open conversation's customer: their details and previous conversations,
 * with what modules add (conversations/partials/customer_sidebar).
 */
class ConversationInspector extends Component
{
    use ConversationOpens;

    public function mount($conversation, $folder = null)
    {
        $this->conversation_id = $conversation->id;
        $this->folder_id = $folder ? $folder->id : $conversation->folder_id;
    }

    public function render()
    {
        $conversation = Conversation::find($this->conversation_id);
        abort_unless($conversation && auth()->user()->can('view', $conversation), 403);
        $data = ConversationsController::pageData($conversation, $this->openedFolder(), auth()->user());

        return view('livewire.conversation-inspector', [
            'conversation'       => $conversation,
            'mailbox'            => $conversation->mailbox,
            'customer'           => $data['customer'],
            'prev_conversations' => $data['prev_conversations'],
        ]);
    }
}
