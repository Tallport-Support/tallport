<?php

namespace App\Livewire;

use App\Conversation;
use App\Http\Controllers\ConversationsController;
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

    public function mount($conversation = null, $folder = null)
    {
        $this->conversation_id = $conversation ? $conversation->id : null;
        $this->folder_id = $folder ? $folder->id : $conversation->folder_id;
    }

    #[\Livewire\Attributes\On('conversation-open')]
    public function openConversation($id, $folder_id = null)
    {
        $this->opened($this->switchTo($id, $folder_id));
    }

    /**
     * Another folder, at its conversation, or without one when it has none; a narrow
     * window (where the list goes first) loads its page.
     */
    #[\Livewire\Attributes\On('folder-open')]
    public function openFolder($folder_id, $conversation_id = null)
    {
        $folder = self::findFolder($folder_id);
        if ($folder && request()->cookie('tallport_narrow')) {
            $this->redirect($folder->id < 0 ? route('mailboxes.all', ['folder_id' => $folder->id]) : $folder->url($folder->mailbox_id), navigate: true);

            return;
        }
        if ($conversation = $this->switchToFolder($folder_id, $conversation_id)) {
            $this->opened($conversation);
        } elseif ($folder && $this->conversation_id === null) {
            $this->openedNone($folder);
        }
    }

    /**
     * What showing a folder without a conversation does, as its page: the page around it follows
     * (public/js/conversations.js).
     */
    protected function openedNone($folder)
    {
        $mailbox = $folder->id < 0 ? \App\Misc\AllMailboxes::mailbox() : $folder->mailbox;
        $this->dispatch('conversation-opened',
            id: null,
            mailbox_id: $mailbox->id,
            folder_id: $folder->id,
            url: \App\Misc\Addresses::folderUrl($folder),
            // As layouts/app titles the folder's page (mailboxes/view); the unread count is the page's.
            title: $folder->getTypeName().' - '.$mailbox->name.' - '.\Eventy::filter('layout.title.name', config('app.name', 'Tallport')),
        );
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
        // Nothing on the page waits for these: after the answer is sent.
        app()->terminating(function () use ($conversation, $user) {
            ConversationsController::markNotificationsRead($conversation, $user);
            \App\Events\RealtimeConvView::dispatchSelf($conversation->id, $user, false);
            \App\Misc\CustomerPhotos::request($conversation->customer, $conversation->customer_email);
        });
        // Read now: an unread row in the list loses its dot. Read already: nothing to write.
        if (\App\ConversationRead::unreadIds([$conversation], $user)) {
            \App\ConversationRead::markRead($conversation->id, $user);
            $this->dispatch('conversations-changed');
            $this->dispatch('sidebar-counts', counts: \App\Misc\Sidebar::unreadCounts($user));
        }
        session()->put('folder_conversation.'.$this->folder_id, $conversation->id);
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
            // Beside the folder in view (App\Misc\Addresses): reload and Back stay there.
            url: \App\Misc\Addresses::forConversation($conversation, $this->openedFolder()),
            title: '#'.$conversation->number.' '.$conversation->getSubject().($customer ? ' - '.$customer->getFullName(true) : ''),
            styles: $styles,
            // Its snapshot in the browser's copy is of this (App\Inbox\Sync::paneVersions()).
            version: \App\Inbox\Sync::paneVersions([$conversation])[$conversation->id],
        );
    }

    /**
     * Changed from the list (ConversationList::changed()): shown anew when it's this one.
     */
    #[\Livewire\Attributes\On('conversation-changed')]
    public function conversationChanged($ids)
    {
        if (!in_array((int) $this->conversation_id, array_map('intval', (array) $ids), true)) {
            $this->skipRender();
        }
    }

    public function render()
    {
        if (!$this->conversation_id) {
            return view('livewire.conversation-pane-none');
        }
        $conversation = $this->shownConversation();
        $user = auth()->user();

        return view('livewire.conversation-pane', ConversationsController::pageData($conversation, $this->openedFolder(), $user) + [
            // Email as email, channels (Telegram, Nostr) as chats.
            'chat_view' => !request()->input('print') && $conversation->hasChannel(),
        ]);
    }
}
