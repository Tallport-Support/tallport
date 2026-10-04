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
 * events, newest first, with the AI summary on top. New messages arrive by the
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
     * The message being edited.
     */
    public $editing = null;

    /**
     * The threads the page has loaded already, for the first render.
     */
    protected $initial;

    public function mount($conversation, $threads = null)
    {
        $this->conversation_id = $conversation->id;
        $this->initial = $threads;
        $this->page_query = request()->query();
        $this->page_uri = request()->getRequestUri();
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
        $threads = $this->initial ?? \Eventy::filter('conversation.view.threads', $conversation->threads()->orderBy('created_at', 'desc')->get());

        return view('livewire.conversation-thread', [
            'conversation' => $conversation,
            'mailbox'      => $conversation->mailbox,
            'customer'     => $conversation->customer,
            'threads'      => $threads,
        ]);
    }
}
