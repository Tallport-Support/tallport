<?php

namespace App\Livewire;

use App\Conversation;
use App\Customer;
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
 * A new conversation: an email to customers, or a phone conversation noted
 * down. Its draft is saved as the user writes (it gets a number and an address
 * then). Sending and drafts share ConversationReplies with the conversation
 * AJAX actions. The browser side is the composer's (tallportComposer in
 * public/js/conversations.js).
 */
class NewConversation extends Component
{
    /**
     * The conversation once its draft is saved.
     */
    #[Locked]
    public $conversation_id;

    #[Locked]
    public $mailbox_id;

    #[Locked]
    public $number;

    /**
     * The thread a new conversation is made from (?from_thread_id=).
     */
    #[Locked]
    public $from_thread_id;

    #[Locked]
    public $after_send;

    #[Locked]
    public $from_aliases = [];

    /**
     * For the composer's script: never a note kept in the browser.
     */
    public $mode = 'new';

    public $type = Conversation::TYPE_EMAIL;

    public $thread_id;

    #[Locked]
    public $submission_key;

    public $customer_id;

    public $from_alias = '';

    /**
     * Addresses, one per line (FruitUI's token fields).
     */
    public $to = '';

    public $cc = '';

    public $bcc = '';

    public $multiple_conversations = false;

    /**
     * A phone conversation's customer: a name (or a customer's ID), a phone
     * number and an email address.
     */
    public $name = '';

    public $phone = '';

    public $to_email = '';

    public $subject = '';

    public $body = '';

    public $status;

    public $user_id;

    public $saved_reply_id = '';

    public $attachments = [];

    /**
     * What the user types in a recipient field or the customer name, for suggestions.
     */
    public $recipient_query = '';

    public $name_query = '';

    public function mount($conversation, $mailbox, $thread = null, $to = [], $name = [], $phone = '', $toEmail = [], $attachments = [], $fromAliases = [], $fromAlias = '', $afterSend = null)
    {
        $this->submission_key = (string) \Illuminate\Support\Str::uuid();
        $this->mailbox_id = $mailbox->id;
        $this->conversation_id = $conversation->id;
        $this->number = $conversation->number;
        $this->from_thread_id = request()->from_thread_id;
        $this->after_send = $afterSend;
        $this->from_aliases = $fromAliases;
        $this->from_alias = $fromAlias && $fromAlias != $mailbox->email ? $fromAlias : '';

        $this->type = $conversation->type == Conversation::TYPE_PHONE ? Conversation::TYPE_PHONE : Conversation::TYPE_EMAIL;
        $this->thread_id = $thread ? $thread->id : null;
        $this->customer_id = $conversation->customer_id;
        $this->subject = (string) old('subject', $conversation->subject);
        $this->body = (string) old('body', $thread ? $thread->body : '');
        $this->to = implode("\n", array_keys($to ?: []));
        if (!$this->to && $thread && $thread->to && !$thread->id) {
            // A new conversation from a forwarded message: its sender.
            $this->to = implode("\n", $thread->getToArray());
        }
        $this->cc = implode("\n", $conversation->getCcArray());
        $this->bcc = implode("\n", $conversation->getBccArray());
        $this->multiple_conversations = false;

        // A phone conversation's customer, by ID.
        $this->name = (string) (array_key_first($name ?: []) ?? '');
        $this->name_query = (string) (reset($name) ?: '');
        $this->phone = (string) $phone;
        $this->to_email = (string) (reset($toEmail) ?: '');

        // Livewire has filled the property with the models: the composer's arrays replace them.
        $this->attachments = [];
        foreach ($attachments as $attachment) {
            $this->attachments[] = [
                'id'    => encrypt($attachment->id),
                'name'  => $attachment->file_name,
                'size'  => $attachment->size,
                'url'   => $attachment->url(),
                'embed' => (bool) $attachment->embedded,
            ];
        }

        // The user's preference.
        $this->status = auth()->user()->replyStatus();
        if (in_array($mailbox->ticket_assignee, [Mailbox::TICKET_ASSIGNEE_REPLYING, Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED])) {
            $this->user_id = auth()->id();
        } else {
            $this->user_id = $conversation->user_id ?: -1;
        }
    }

    public function switchType($type)
    {
        $this->type = $type == Conversation::TYPE_PHONE ? Conversation::TYPE_PHONE : Conversation::TYPE_EMAIL;
    }

    /**
     * A phone conversation's customer, chosen from the suggestions.
     */
    public function chooseCustomer($customer_id)
    {
        $customer = Customer::find($customer_id);
        if (!$customer || !auth()->user()->can('view', $customer)) {
            return;
        }
        $this->name = (string) $customer->id;
        $this->name_query = $customer->getFullName(true);
        $this->customer_id = $customer->id;
        if (!$this->phone && ($phones = $customer->getPhones())) {
            $this->phone = (string) ($phones[0]['value'] ?? '');
        }
        if (!$this->to_email && $customer->getMainEmail()) {
            $this->to_email = $customer->getMainEmail();
        }
    }

    /**
     * The customer's name as typed: a new customer, unless one is chosen.
     */
    public function updatedNameQuery()
    {
        $this->name = $this->name_query;
        $this->customer_id = null;
    }

    public function saveDraft($force = false)
    {
        if (!$force && !$this->thread_id && trim(strip_tags($this->body)) === '' && !$this->attachments && trim($this->to) === '') {
            $this->skipRender();

            return;
        }

        $response = $this->call('saveDraft');
        if (($response['status'] ?? '') != 'success') {
            Fruit::toast($response['msg'] ?? __('Error occurred'), 'danger');

            return;
        }
        $this->thread_id = $response['thread_id'] ?? $this->thread_id;
        $this->customer_id = $response['customer_id'] ?? $this->customer_id;
        if (empty($this->conversation_id) && !empty($response['conversation_id'])) {
            // The draft is a conversation now: its number, and its address.
            $this->conversation_id = $response['conversation_id'];
            $this->number = $response['number'] ?? null;
            $this->js('window.history.replaceState({}, "", '.json_encode(route('conversations.view', ['id' => $this->conversation_id])).')');
        }
        $this->dispatch('composer-draft-saved');
    }

    #[On('composer-discard-draft')]
    public function discard()
    {
        $response = app(ConversationReplies::class)->discardDraft(['thread_id' => $this->thread_id, 'from_thread_id' => $this->from_thread_id], auth()->user());
        if (($response['status'] ?? '') != 'success') {
            Fruit::toast($response['msg'] ?? __('Error occurred'), 'danger');

            return;
        }
        $this->redirect($response['redirect_url'] ?? Mailbox::find($this->mailbox_id)->url());
    }

    public function send($status = null)
    {
        if ($status !== null && $status !== '') {
            $this->status = (int) $status;
        }
        if (trim(str_replace('&nbsp;', ' ', strip_tags((string) $this->body, '<img>'))) === '') {
            Fruit::toast(__('Please enter a message'), 'danger');

            return;
        }

        $response = $this->call('sendReply');
        if (($response['status'] ?? '') != 'success') {
            Fruit::toast($response['msg'] ?? __('Error occurred'), 'danger');

            return;
        }
        $this->redirect($response['redirect_url'] ?? Mailbox::find($this->mailbox_id)->url());
    }

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

    #[On('composer-saved-reply')]
    public function savedReply($id, $attachments = [])
    {
        $this->saved_reply_id = (int) $id;
        $this->attach($attachments);
    }

    /**
     * Customers matching what the user types in a recipient field (email => name).
     */
    #[Computed]
    public function recipientMatches()
    {
        return collect($this->searchCustomers($this->recipient_query, 'all'))->mapWithKeys(fn ($result) => [
            $result['id'] => trim(preg_replace('/\s*<[^>]*>$/', '', (string) $result['text'])) ?: $result['id'],
        ])->all();
    }

    /**
     * Customers matching the phone conversation's customer name (id => name and email).
     */
    #[Computed]
    public function nameMatches()
    {
        if ($this->customer_id) {
            return [];
        }

        return collect($this->searchCustomers($this->name_query, 'name', true))->pluck('text', 'id')->all();
    }

    protected function searchCustomers($query, $search_by, $use_id = false)
    {
        $query = trim((string) $query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $data = app(\App\Http\Controllers\CustomersController::class)
            ->ajaxSearch(new \Illuminate\Http\Request(['q' => $query, 'search_by' => $search_by, 'use_id' => $use_id, 'allow_non_emails' => $use_id]))
            ->getData(true);

        return $data['results'] ?? [];
    }

    /**
     * Runs a reply operation with the fields the form posted.
     */
    protected function call($method)
    {
        $is_phone = $this->type == Conversation::TYPE_PHONE;
        $attachment_ids = array_column($this->attachments, 'id');
        $fields = [
            'conversation_id'        => $this->conversation_id,
            'mailbox_id'             => $this->mailbox_id,
            'is_note'                => $is_phone ? 1 : '',
            'is_phone'               => $is_phone ? 1 : '',
            'type'                   => $this->type,
            'thread_id'              => $this->thread_id,
            'submission_key'         => $this->submission_key,
            'customer_id'            => $this->customer_id,
            'is_create'              => 1,
            'saved_reply_id'         => $this->saved_reply_id,
            'subject'                => $this->subject,
            'body'                   => $this->body,
            'status'                 => $this->status,
            'user_id'                => $this->user_id,
            'after_send'             => $this->after_send,
            'attachments'            => $attachment_ids,
            'attachments_all'        => $attachment_ids,
            'embeds'                 => array_column(array_filter($this->attachments, fn ($attachment) => !empty($attachment['embed'])), 'id'),
        ];
        if ($is_phone) {
            $fields['name'] = $this->name;
            $fields['phone'] = $this->phone;
            $fields['to_email'] = $this->to_email;
        } else {
            $fields['from_alias'] = $this->from_alias;
            $fields['to'] = Fruit::tokens($this->to);
            $fields['cc'] = Fruit::tokens($this->cc);
            $fields['bcc'] = Fruit::tokens($this->bcc);
            $fields['multiple_conversations'] = $this->multiple_conversations ? 1 : '';
        }
        $replies = app(ConversationReplies::class);

        return $replies->$method($fields, auth()->user());
    }

    public function render()
    {
        $user = auth()->user();
        $mailbox = Mailbox::find($this->mailbox_id);
        if (!$mailbox || !$user || !$user->can('view', $mailbox)) {
            abort(403);
        }
        $conversation = $this->conversation_id ? Conversation::find($this->conversation_id) : null;
        if (!$conversation) {
            $conversation = new Conversation();
            $conversation->mailbox_id = $mailbox->id;
            $conversation->type = $this->type;
        }

        // The customer the email goes to, beside the form.
        $recipients = Fruit::tokens($this->to);
        $customer = null;
        $prev_conversations = [];
        $email = $this->type == Conversation::TYPE_PHONE ? $this->to_email : (count($recipients) == 1 ? $recipients[0] : '');
        if ($email && ($customer = Customer::getByEmail($email)) && $user->can('view', $customer)) {
            $prev_conversations = $mailbox->conversations()
                ->where('customer_id', $customer->id)
                ->where('id', '<>', (int) $this->conversation_id)
                ->where('status', '!=', Conversation::STATUS_SPAM)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
                ->paginate(Conversation::PREV_CONVERSATIONS_LIMIT);
        } else {
            $customer = null;
        }

        $noreply = [];
        // And addresses emails failed to reach (To and Cc): a warning, sending still works.
        $delivery_problems = [];
        if ($this->type != Conversation::TYPE_PHONE) {
            foreach (array_unique(array_merge($recipients, Fruit::tokens($this->cc), Fruit::tokens($this->bcc))) as $address) {
                if (Noreply::isNoreply($address)) {
                    $noreply[] = $address;
                }
            }
            $delivery_problems = DeliveryReports::flagged(array_merge($recipients, Fruit::tokens($this->cc)));
        }

        return view('livewire.new-conversation', [
            'mailbox'            => $mailbox,
            'conversation'       => $conversation,
            'customer'           => $customer,
            'prev_conversations' => $prev_conversations,
            'recipients'         => $recipients,
            'noreply'            => $noreply,
            'delivery_problems'  => $delivery_problems,
        ]);
    }
}
