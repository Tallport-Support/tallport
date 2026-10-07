<?php

namespace App\Livewire;

use App\Conversation;
use App\Misc\ConversationActions;
use App\Thread;
use FruitUI\Fruit;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The open conversation's history (x-fruit::thread): its messages, notes and
 * events, newest first, with the AI summary on top; in the chat view ($chat)
 * oldest first, in a history (x-fruit::history) that opens at the newest. New messages arrive by the
 * realtime script (conversation-thread-created). A user edits a message in
 * place, deletes a note or sends a failed reply again.
 */
class ConversationThread extends Component
{
    #[Locked]
    public $conversation_id;

    /**
     * The page's query and address, for the links the messages' menus build
     * from them (Outgoing Emails, Show Original, Print).
     */
    #[Locked]
    public $page_query = [];

    #[Locked]
    public $page_uri = '';

    /**
     * The chat view (the user's Conversation View for the channel).
     */
    #[Locked]
    public $chat = false;

    /**
     * The message being edited.
     */
    public $editing = null;

    /**
     * The threads the page has loaded already, for the first render.
     */
    protected $initial;

    public function mount($conversation, $threads = null, $chat = false)
    {
        $this->conversation_id = $conversation->id;
        $this->chat = (bool) $chat;
        $this->initial = $threads;
        $this->page_query = request()->query();
        $this->page_uri = request()->getRequestUri();
        // Opened in place (ConversationPane): the conversation's page, not Livewire's request.
        if (\Livewire\Livewire::isLivewireRequest()) {
            $this->page_query = ['folder_id' => request()->input('folder_id')];
            $this->page_uri = $conversation->url(request()->input('folder_id'));
        }
    }

    public function edit($thread_id)
    {
        $thread = $this->thread($thread_id);
        if ($thread && auth()->user()->can('edit', $thread)) {
            $this->editing = $thread->id;
        }
    }

    public function saveEdit($thread_id, $body)
    {
        $result = ConversationActions::editThread($this->thread($thread_id), $body, auth()->user());
        if (!empty($result['msg'])) {
            $this->skipRender();
            Fruit::toast($result['msg'], 'danger');

            return;
        }
        $this->editing = null;
    }

    public function deleteNote($thread_id)
    {
        $result = ConversationActions::deleteNote($this->thread($thread_id), auth()->user());
        if (!empty($result['msg'])) {
            Fruit::toast($result['msg'], 'danger');
        }
    }

    public function retry($thread_id)
    {
        $result = ConversationActions::retrySend($this->thread($thread_id), auth()->user());
        if (!empty($result['msg'])) {
            Fruit::toast($result['msg'], 'danger');
        }
    }

    /**
     * Translate a message into the user's language now (its menu's Translate).
     */
    public function translate($thread_id)
    {
        $thread = $this->thread($thread_id);
        $user = auth()->user();
        if (!$thread || !\App\Ai\Translations::canForce($thread, $user)) {
            $this->skipRender();

            return;
        }
        if (!\App\Ai\Settings::withinBudget($thread->conversation->mailbox)) {
            Fruit::toast(__('This mailbox has used its AI tokens for today.'), 'danger');

            return;
        }
        @set_time_limit(180);
        try {
            if (!\App\Ai\Translations::forceTranslate($thread, $user)) {
                Fruit::toast(__('The AI took this message to be in :language already.', ['language' => \App\Ai\Settings::displayName(\App\Ai\Settings::language($thread->conversation->mailbox, $user))]));
            }
        } catch (\Throwable $e) {
            \Helper::logException($e, '[AI] Translation of thread '.$thread->id.':');
            Fruit::toast(__('Could not translate the message: :error', ['error' => $e->getMessage()]), 'danger');
        }
    }

    /**
     * A new message or event (the realtime script).
     */
    #[On('conversation-thread-created')]
    public function refresh()
    {
    }

    /**
     * One of this conversation's threads.
     */
    protected function thread($thread_id)
    {
        return Thread::where('conversation_id', $this->conversation()->id)->find($thread_id);
    }

    protected function conversation()
    {
        $conversation = Conversation::find($this->conversation_id);
        if (!$conversation || !auth()->user() || !auth()->user()->can('view', $conversation)) {
            abort(403);
        }

        return $conversation;
    }

    public function render()
    {
        $conversation = $this->conversation();
        $threads = $this->initial ?? \Eventy::filter('conversation.view.threads', $conversation->threads()->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get());
        if ($this->chat && $this->initial === null) {
            $threads = $threads->reverse()->values();
        }
        // In a chat, a reply being written stays in its composer: drafts aren't messages.
        if ($this->chat) {
            $threads = $threads->filter(fn ($thread) => $thread->state != \App\Thread::STATE_DRAFT)->values();
        }

        return view('livewire.conversation-thread', [
            'conversation' => $conversation,
            'mailbox'      => $conversation->mailbox,
            'customer'     => $conversation->customer,
            'threads'      => $threads,
        ]);
    }
}
