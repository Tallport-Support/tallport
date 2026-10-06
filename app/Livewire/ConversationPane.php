<?php

namespace App\Livewire;

use App\Conversation;
use App\Http\Controllers\ConversationsController;
use App\User;
use Livewire\Component;

/**
 * The open conversation's column: its heading, history and composer, in the
 * email or the chat view (the user's choice for its channel). Another
 * conversation opens in it in place (ConversationOpens); opening one is what
 * the page does when loaded: it's viewed, its notifications are read and the
 * folder opens at it next time.
 */
class ConversationPane extends Component
{
    use ConversationOpens;

    public function mount($conversation, $folder = null)
    {
        $this->conversation_id = $conversation->id;
        $this->folder_id = $folder ? $folder->id : $conversation->folder_id;
    }

    #[\Livewire\Attributes\On('conversation-open')]
    public function openConversation($id, $folder_id = null)
    {
        $this->opened($this->switchTo($id, $folder_id));
    }

    /**
     * Another folder, at its conversation; one without conversations (or a narrow
     * window, where the list goes first) loads its page.
     */
    #[\Livewire\Attributes\On('folder-open')]
    public function openFolder($folder_id, $conversation_id = null)
    {
        $folder = self::findFolder($folder_id);
        $conversation = request()->cookie('tallport_narrow') ? null : $this->switchToFolder($folder_id, $conversation_id);
        if (!$conversation) {
            if ($folder) {
                $this->redirect($folder->id < 0 ? route('mailboxes.all', ['folder_id' => $folder->id]) : $folder->url($folder->mailbox_id), navigate: true);
            }

            return;
        }
        $this->opened($conversation);
    }

    /**
     * What opening a conversation does, as the page does when loaded.
     */
    protected function opened($conversation)
    {
        if (!$conversation) {
            return;
        }
        // A draft opens in its own page (conversations/create).
        if ($conversation->state == Conversation::STATE_DRAFT) {
            $this->redirect($conversation->url($this->folder_id), navigate: true);

            return;
        }
        $user = auth()->user();
        ConversationsController::markNotificationsRead($conversation, $user);
        session()->put('folder_conversation.'.$this->folder_id, $conversation->id);
        \App\Events\RealtimeConvView::dispatchSelf($conversation->id, $user, false);
        \App\Misc\Gravatar::request($conversation->customer, $conversation->customer_email);
        \Eventy::action('conversation.view.start', $conversation, request());

        // The page around it follows (public/js/conversations.js); loaded anew if its styles
        // changed since (an update), so the new markup doesn't meet the old styles.
        $customer = $conversation->customer_cached;
        try {
            $styles = \Minify::stylesheet(\Helper::layoutStylesheets())->url();
        } catch (\Exception $e) {
            $styles = null;
        }
        $this->dispatch('conversation-opened',
            id: $conversation->id,
            mailbox_id: $conversation->mailbox_id,
            folder_id: $this->folder_id,
            url: route('conversations.view', ['id' => $conversation->id, 'folder_id' => $this->folder_id]),
            title: '#'.$conversation->number.' '.$conversation->getSubject().($customer ? ' - '.$customer->getFullName(true) : ''),
            styles: $styles,
        );
    }

    public function render()
    {
        $conversation = Conversation::find($this->conversation_id);
        abort_unless($conversation && auth()->user()->can('view', $conversation), 403);
        // Its parts read the folder from the request, as on the page.
        request()->merge(['folder_id' => $this->folder_id]);
        $user = auth()->user();

        return view('livewire.conversation-pane', ConversationsController::pageData($conversation, $this->openedFolder(), $user) + [
            'chat_view' => !request()->input('print') && $user->conversationView($conversation->channel ?: null) == User::VIEW_CHAT,
        ]);
    }
}
