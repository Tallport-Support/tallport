<?php

namespace App\Livewire;

use App\Conversation;
use App\Mailbox;
use App\Misc\ConversationReplies;
use App\Misc\DeliveryReports;
use App\Misc\Noreply;
use App\Thread;
use FruitUI\Fruit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The open conversation's composer: a reply, a note or a forward, its
 * recipients, attachments and drafts, and the status and assignee it leaves
 * the conversation with. Sending and drafts share ConversationReplies with
 * the conversation AJAX actions.
 *
 * The editor's text reaches the component with each action (public/js/conversations.js),
 * which also keeps unsent notes in the browser and saves drafts as the user writes.
 */
class ConversationComposer extends Component
{
    #[Locked]
    public $conversation_id;

    /**
     * The folder the conversation was opened from and the embedded view, for
     * where the user goes after sending.
     */
    #[Locked]
    public $folder_id;

    #[Locked]
    public $x_embed;

    /**
     * The customer's addresses to reply to (email => label), the mailbox's
     * aliases to send from (email => name) and the user's after-send setting.
     */
    #[Locked]
    public $to_customers = [];

    #[Locked]
    public $from_aliases = [];

    #[Locked]
    public $after_send;

    /**
     * The chat view: the composer stays open below the history, a reply unless
     * switched to a note, Enter sends (App\Misc\KeyboardShortcuts), and Send is
     * the only action: it leaves the status as it is (the toolbar's).
     */
    #[Locked]
    public $chat = false;

    /**
     * reply, note or forward; empty while the composer is closed.
     */
    public $mode = '';

    /**
     * The draft being written.
     */
    public $thread_id = null;

    #[Locked]
    public $submission_key;

    public $from_alias = '';

    /**
     * The address a reply goes to (one of to_customers), and a forward's
     * addresses, one per line (FruitUI's token field).
     */
    public $to = '';

    public $to_email = '';

    public $cc = '';

    public $bcc = '';

    public $body = '';

    public $status;

    public $user_id;

    public $conv_history = '';

    public $saved_reply_id = '';

    /**
     * Uploaded files: id (encrypted), name, size, url, and embed for images in the text.
     */
    public $attachments = [];

    public $ai_draft_translation = '';

    public $ai_draft_translation_language = '';

    /**
     * A reply's translation for the customer (a chat's or an email's), previewed before it's
     * sent (App\Ai\ChatTranslation): source (the agent's text), html (the translation), error.
     */
    public $translation = null;

    /**
     * What the user types in a recipient field, for its suggestions.
     */
    public $recipient_query = '';

    /**
     * The Cc the page suggests for a reply.
     */
    #[Locked]
    public $default_cc = [];

    public function mount($conversation, $toCustomers = [], $cc = [], $fromAliases = [], $fromAlias = '', $afterSend = null, $chat = false)
    {
        $this->chat = (bool) $chat;
        $this->conversation_id = $conversation->id;
        $this->folder_id = Conversation::getFolderParam();
        $this->x_embed = request()->x_embed;
        foreach ($toCustomers as $to_customer) {
            $this->to_customers[$to_customer['email']] = $to_customer['customer']->getFullName(true).' <'.$to_customer['email'].'>';
        }
        $this->default_cc = array_values($cc ?: []);
        $this->from_aliases = $fromAliases;
        $this->from_alias = $fromAlias && $fromAlias != $conversation->mailbox->email ? $fromAlias : '';
        $this->after_send = $afterSend;
        $this->resetFields($conversation);

        // A draft to continue (?show_draft=); in a chat, the user's own unsent reply is back
        // in the composer (the chat doesn't show drafts).
        if (request()->show_draft) {
            $this->editDraft(request()->show_draft);
        } elseif ($this->chat) {
            $this->mode = 'reply';
            $own_draft = Thread::where('conversation_id', $conversation->id)->where('state', Thread::STATE_DRAFT)
                ->where('type', Thread::TYPE_MESSAGE)->where('created_by_user_id', auth()->id())
                ->orderBy('id', 'desc')->first();
            if ($own_draft) {
                $this->editDraft($own_draft->id);
            }
        }
    }

    /**
     * Opens the composer for a reply, a note or a forward. An open composer
     * stays as it is: switching would leave a draft behind (in the chat view, an
     * empty one switches).
     */
    #[On('composer-open')]
    public function open($mode)
    {
        if (!in_array($mode, ['reply', 'note', 'forward']) || $this->mode && !($this->chat && $this->mode != $mode && !$this->hasText() && !$this->thread_id)) {
            return;
        }
        $conversation = $this->conversation();
        $this->resetFields($conversation);
        $this->mode = $mode;
        // The reply template (public/js/saved_replies.js).
        $this->dispatch('composer-opened', mode: $mode);

        if ($mode == 'note') {
            // A note keeps the status and the assignee.
            $this->status = $conversation->status;
            $this->user_id = $conversation->user_id ?: -1;
        } elseif ($mode == 'forward') {
            $this->to = '';
            $this->cc = '';
            // A forward quotes the whole conversation by default.
            $this->conv_history = 'full';
            $this->attachments = $this->forwardAttachments($conversation);
            // Saved now, so that the copied files belong to the draft.
            $this->saveDraft(true);
        }
    }

    /**
     * A note kept in the browser while it was being written.
     */
    public function openNote($body)
    {
        if ($this->mode) {
            return;
        }
        $this->open('note');
        $this->body = (string) $body;
        $this->dispatch('fruit-editor-set', target: 'body', html: $this->body);
    }

    #[On('composer-edit-draft')]
    public function editDraft($thread_id)
    {
        $thread = Thread::where('conversation_id', $this->conversation()->id)->find($thread_id);
        if (!$thread || $thread->state != Thread::STATE_DRAFT) {
            Fruit::toast(__('Thread not found'), 'danger');

            return;
        }

        $this->resetFields($thread->conversation);
        $this->mode = $thread->isForward() ? 'forward' : 'reply';
        $this->thread_id = $thread->id;
        $this->from_alias = $thread->from && $thread->from != $thread->conversation->mailbox->email ? $thread->from : '';
        if ($thread->isForward()) {
            $this->to_email = (string) $thread->getToFirst();
        } elseif ($thread->getToFirst()) {
            $this->to = $thread->getToFirst();
        }
        $this->cc = implode("\n", $thread->getCcArray());
        $this->bcc = implode("\n", $thread->getBccArray());
        $this->body = (string) $thread->body;
        $this->attachments = [];
        foreach ($thread->attachments as $attachment) {
            $this->attachments[] = $this->attachmentData($attachment, (bool) $attachment->embedded);
        }
        $this->dispatch('fruit-editor-set', target: 'body', html: $this->body);
    }

    /**
     * Saves the draft of a reply or a forward (a note stays in the browser).
     * An empty new draft is not saved.
     */
    public function saveDraft($force = false)
    {
        if (!in_array($this->mode, ['reply', 'forward'])) {
            $this->skipRender();

            return;
        }
        if (!$force && !$this->thread_id && !$this->hasText() && !$this->attachments) {
            $this->skipRender();

            return;
        }

        $response = $this->call('saveDraft');
        if (($response['status'] ?? '') != 'success') {
            Fruit::toast($response['msg'] ?? __('Error occurred'), 'danger');

            return;
        }
        if (!empty($response['thread_id'])) {
            $this->thread_id = $response['thread_id'];
        }
        $this->dispatch('composer-draft-saved');
    }

    /**
     * Discards the draft being written, or one in the conversation's history.
     */
    #[On('composer-discard-draft')]
    public function discard($thread_id = null)
    {
        $thread_id = $thread_id ?: $this->thread_id;
        $current = !$thread_id || $thread_id == $this->thread_id;

        if ($thread_id) {
            $response = app(ConversationReplies::class)->discardDraft(['thread_id' => $thread_id, 'from_thread_id' => ''], auth()->user());
            if (($response['status'] ?? '') != 'success') {
                Fruit::toast($response['msg'] ?? __('Error occurred'), 'danger');

                return;
            }
            $this->dispatch('conversation-thread-created');
        }

        if ($current) {
            if ($this->mode == 'note') {
                $this->dispatch('composer-note-forget');
            }
            $this->resetFields($this->conversation());
            $this->mode = $this->chat ? 'reply' : '';
            $this->dispatch('fruit-editor-set', target: 'body', html: '');
        }
    }

    /**
     * Sends the reply, adds the note or forwards; with a status from the send menu.
     */
    /**
     * Sends the message: true once it's saved (delivery follows in the background, and a
     * failure shows on the message: SendReplyToCustomer and the channels' jobs). $body: the
     * editor's text as the browser sent it, which may clear the editor at once (the message
     * already shows, public/js/conversations.js). The chat view stays where it is: the history
     * shows the message, and the composer is ready for the next one.
     */
    public function send($status = null, $body = null)
    {
        if ($status !== null && $status !== '') {
            $this->status = (int) $status;
        }
        if ($body !== null) {
            $this->body = (string) $body;
        }
        if (!$this->hasText()) {
            Fruit::toast(__('Please enter a message'), 'danger');

            return false;
        }

        $is_note = $this->mode == 'note';
        $response = $this->call('sendReply');
        if (($response['status'] ?? '') != 'success') {
            Fruit::toast($response['msg'] ?? __('Error occurred'), 'danger');

            return false;
        }
        if ($is_note) {
            $this->dispatch('composer-note-forget');
        }
        if ($this->chat) {
            $this->resetFields($this->conversation());
            $this->mode = 'reply';
            $this->dispatch('fruit-editor-set', target: 'body', html: '');
            $this->dispatch('conversation-thread-created');

            return true;
        }
        $this->redirect($response['redirect_url'] ?? $this->conversation()->url());

        return true;
    }

    /**
     * Whether this reply goes out translated (App\Ai\ChatTranslation): a chat's or an email's,
     * not a forward (for others) or a note.
     */
    #[Computed]
    public function translating()
    {
        return $this->mode == 'reply' && \App\Ai\ChatTranslation::needed($this->conversation(), auth()->user());
    }

    /**
     * A reply's translation being written is shown at most this often (seconds).
     */
    const STREAM_INTERVAL = 0.1;

    /**
     * The reply translated for a preview: "ready", "error" (shown, with Send as Written),
     * "same" (in the customer's language already: sent as it is) or "off".
     */
    public function previewTranslation($html)
    {
        if (!$this->translating()) {
            return 'off';
        }
        $this->body = (string) $html;
        if (!$this->hasText()) {
            Fruit::toast(__('Please enter a message'), 'danger');

            return 'empty';
        }
        @set_time_limit(180);
        // Shown as it's written (wire:stream in conversations/partials/chat_translation).
        $throttle = new \App\Ai\StreamThrottle(self::STREAM_INTERVAL);
        $stream = function ($translation) use ($throttle) {
            if (trim($translation) !== '' && $throttle->ready()) {
                // A tag still being written is left out.
                $this->stream(content: safe_raw_html(preg_replace('/<[^>]*$/', '', $translation)), replace: true, name: 'translation');
            }
        };
        try {
            $result = \App\Ai\ChatTranslation::translateReply($this->conversation(), $this->body, auth()->user(), $stream);
        } catch (\Throwable $e) {
            $error = $e->getMessage() === __('This mailbox has used its AI tokens for today.')
                ? $e->getMessage()
                : \App\Ai\Errors::message($e, 'Translation of a reply in conversation '.$this->conversation_id.':');
            $this->translation = ['source' => $this->body, 'html' => '', 'error' => $error];

            return 'error';
        }
        if (!empty($result['same'])) {
            $this->translation = null;

            return 'same';
        }
        $this->translation = ['source' => $this->body, 'html' => $result['html'], 'error' => ''];

        return 'ready';
    }

    /**
     * Send the previewed translation, with the agent's text kept beside it, and the status from
     * the send menu if one was chosen: "sent", "failed", or "changed" (the text isn't the one
     * translated: it's translated again).
     */
    public function sendTranslation($html, $status = null)
    {
        if (empty($this->translation['html']) || $this->translation['source'] !== (string) $html) {
            return 'changed';
        }
        $this->ai_draft_translation = \Helper::htmlToText($this->translation['source']);
        $this->ai_draft_translation_language = \App\Ai\ChatTranslation::agentLanguage($this->conversation(), auth()->user());
        $body = $this->translation['html'];
        $this->translation = null;

        return $this->send($status, $body) ? 'sent' : 'failed';
    }

    public function discardTranslation()
    {
        $this->translation = null;
    }

    /**
     * The customer's language was changed (the sidebar, customers/profile_snippet): a preview
     * in the one before is dropped, and the next is in the new one.
     */
    #[On('customer-language-changed')]
    public function customerLanguageChanged()
    {
        $this->translation = null;
        unset($this->translating);
    }

    /**
     * Files uploaded to conversations.upload (public/js/conversations.js), or
     * brought by a saved reply.
     */
    #[On('composer-attach')]
    public function attach($attachments)
    {
        foreach ((array) $attachments as $attachment) {
            if (empty($attachment['id'])) {
                continue;
            }
            $this->attachments[] = [
                'id'    => (string) $attachment['id'],
                'name'  => (string) ($attachment['name'] ?? ''),
                'size'  => (int) ($attachment['size'] ?? 0),
                'url'   => (string) ($attachment['url'] ?? ''),
                'embed' => !empty($attachment['embed']),
            ];
        }
    }

    public function removeAttachment($id)
    {
        $this->attachments = array_values(array_filter($this->attachments, fn ($attachment) => $attachment['id'] != $id));
    }

    /**
     * A saved reply was inserted (public/js/saved_replies.js).
     */
    #[On('composer-saved-reply')]
    public function savedReply($id, $attachments = [])
    {
        $this->saved_reply_id = (int) $id;
        $this->attach($attachments);
    }

    /**
     * An AI draft goes in a reply, with its translation (tallportAiDraft in public/js/conversations.js).
     */
    #[On('composer-ai-draft')]
    public function aiDraft($html, $translation = '', $language = '')
    {
        if ($this->mode != 'reply') {
            $this->mode = '';
            $this->open('reply');
        }
        $this->body = (string) $html;
        $this->ai_draft_translation = (string) $translation;
        $this->ai_draft_translation_language = (string) $language;
        $this->dispatch('fruit-editor-set', target: 'body', html: $this->body);
    }

    /**
     * Customers matching what the user types in a recipient field (email => name).
     */
    #[Computed]
    public function recipientMatches()
    {
        $query = trim((string) $this->recipient_query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $data = app(\App\Http\Controllers\CustomersController::class)
            ->ajaxSearch(new \Illuminate\Http\Request(['q' => $query, 'search_by' => 'all']))
            ->getData(true);

        // The name: the field shows the address too.
        return collect($data['results'] ?? [])->mapWithKeys(fn ($result) => [
            $result['id'] => trim(preg_replace('/\s*<[^>]*>$/', '', (string) $result['text'])) ?: $result['id'],
        ])->all();
    }

    public function switchToNote()
    {
        $body = $this->body;
        $this->mode = '';
        $this->open('note');
        $this->body = $body;
    }

    /**
     * Runs a reply operation with the fields the reply form posted.
     */
    protected function call($method)
    {
        $conversation = $this->conversation();
        $attachment_ids = array_column($this->attachments, 'id');
        $fields = [
            'conversation_id' => $conversation->id,
            'mailbox_id'      => $conversation->mailbox_id,
            'saved_reply_id'  => $this->saved_reply_id,
            'thread_id'       => $this->thread_id,
            'submission_key'  => $this->submission_key,
            'is_note'         => $this->mode == 'note' ? 1 : '',
            'subtype'         => $this->mode == 'forward' ? Thread::SUBTYPE_FORWARD : '',
            'conv_history'    => $this->conv_history,
            'from_alias'      => $this->from_alias,
            'cc'              => $this->mode == 'note' ? [] : Fruit::tokens($this->cc),
            'bcc'             => $this->mode == 'note' ? [] : Fruit::tokens($this->bcc),
            'body'            => $this->body,
            'status'          => $this->status,
            'user_id'         => $this->user_id,
            // The chat view stays in the conversation.
            'after_send'      => $this->chat ? \App\MailboxUser::AFTER_SEND_STAY : $this->after_send,
            'attachments'     => $attachment_ids,
            'attachments_all' => $attachment_ids,
            'embeds'          => array_column(array_filter($this->attachments, fn ($attachment) => !empty($attachment['embed'])), 'id'),
            'folder_id'       => $this->folder_id,
            'x_embed'         => $this->x_embed,
        ];
        if ($this->mode == 'forward') {
            $fields['to_email'] = Fruit::tokens($this->to_email);
        } elseif ($this->to_customers) {
            $fields['to'] = $this->to;
        }
        if ($this->ai_draft_translation !== '') {
            $fields['ai_draft_translation'] = $this->ai_draft_translation;
            $fields['ai_draft_translation_language'] = $this->ai_draft_translation_language;
        }
        $replies = app(ConversationReplies::class);

        return $replies->$method($fields, auth()->user());
    }

    protected function hasText()
    {
        // Visually empty content (e.g. <p></p>) counts as empty, images do not.
        return trim(str_replace('&nbsp;', ' ', strip_tags((string) $this->body, '<img>'))) !== '';
    }

    /**
     * The fields of a new reply: the mailbox's default status and assignee.
     */
    protected function resetFields($conversation)
    {
        $mailbox = $conversation->mailbox;
        $user = auth()->user();

        $this->thread_id = null;
        $this->submission_key = (string) \Illuminate\Support\Str::uuid();
        $this->body = '';
        $this->attachments = [];
        $this->saved_reply_id = '';
        $this->ai_draft_translation = '';
        $this->ai_draft_translation_language = '';
        $this->translation = null;
        $this->to = array_key_exists($conversation->customer_email, $this->to_customers) ? $conversation->customer_email : (array_key_first($this->to_customers) ?? '');
        $this->to_email = '';
        $this->cc = implode("\n", $this->default_cc);
        $this->bcc = '';
        $this->conv_history = '';

        // The user's preference; in the chat view a message leaves the status as the
        // agent set it (the toolbar's).
        $this->status = $this->chat ? $conversation->status : $user->replyStatus();

        // The assignee, as the mailbox's setting says.
        if ($mailbox->ticket_assignee == Mailbox::TICKET_ASSIGNEE_ANYONE
            || ($mailbox->ticket_assignee == Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT && !$conversation->user_id)
        ) {
            $this->user_id = -1;
        } elseif ($mailbox->ticket_assignee == Mailbox::TICKET_ASSIGNEE_REPLYING
            || ($mailbox->ticket_assignee == Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED && !$conversation->user_id)
        ) {
            $this->user_id = $user->id;
        } else {
            $this->user_id = $conversation->user_id ?: -1;
        }
    }

    /**
     * Copies of the conversation's files for a forward.
     */
    protected function forwardAttachments($conversation)
    {
        $attachments = [];
        if ($conversation->has_attachments) {
            foreach ($conversation->threads as $thread) {
                if ($thread->has_attachments && (!$thread->isDraft() || count($conversation->threads) == 1)) {
                    foreach ($thread->attachments as $attachment) {
                        $copy = $attachment->duplicate();
                        if ($copy) {
                            $attachments[] = $this->attachmentData($copy);
                        }
                    }
                }
            }
        }

        return $attachments;
    }

    protected function attachmentData($attachment, $embed = false)
    {
        return [
            'id'    => encrypt($attachment->id),
            'name'  => $attachment->file_name,
            'size'  => $attachment->size,
            'url'   => $attachment->url(),
            'embed' => $embed,
        ];
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
        $mailbox = $conversation->mailbox;

        // Addresses that do not read replies.
        $noreply = [];
        // And addresses emails failed to reach (To and Cc): a warning, sending still works.
        $delivery_problems = [];
        if ($this->mode && $this->mode != 'note') {
            $to_cc = array_merge($this->mode == 'forward' ? Fruit::tokens($this->to_email) : [$this->to ?: $conversation->customer_email], Fruit::tokens($this->cc));
            $recipients = array_merge($to_cc, Fruit::tokens($this->bcc));
            foreach (array_unique(array_filter($recipients)) as $email) {
                if (Noreply::isNoreply($email)) {
                    $noreply[] = $email;
                }
            }
            $delivery_problems = DeliveryReports::flagged($to_cc);
        }

        $threads = $conversation->threads()->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(1)->get();

        return view('livewire.conversation-composer', [
            'conversation' => $conversation,
            'mailbox'      => $mailbox,
            'noreply'      => $noreply,
            'delivery_problems' => $delivery_problems,
            'last_thread'  => $threads->first(),
        ]);
    }
}
