<?php

namespace App\Http\Controllers\Api;

use App\Api\Format;
use App\Api\Writer;
use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\User;
use Illuminate\Http\Request;

class ConversationsController extends ApiController
{
    /**
     * GET /api/conversations
     */
    public function index(Request $request)
    {
        $access = $this->access();
        $query = Conversation::select('conversations.*');

        $mailbox_ids = $this->param($request, 'mailboxId');
        if ($mailbox_ids) {
            $mailbox_ids = array_map('intval', explode(',', (string) $mailbox_ids));
            foreach ($mailbox_ids as $mailbox_id) {
                if (!$access->canMailbox($mailbox_id)) {
                    return $this->forbiddenMailbox();
                }
            }
            $query->whereIn('conversations.mailbox_id', $mailbox_ids);
        } elseif (!$access->isGlobal()) {
            $query->whereIn('conversations.mailbox_id', $access->mailboxIds());
        }
        if (!$access->isGlobal() && $access->user()->canSeeOnlyAssignedConversations()) {
            $query->where('conversations.user_id', $access->user()->id);
        }

        if ($this->param($request, 'tag')) {
            return $this->error('Tags module is not installed or not activated');
        }
        if ($folder_id = $this->param($request, 'folderId')) {
            $query->where('conversations.folder_id', $folder_id);
        }
        if ($status = $this->param($request, 'status')) {
            $codes = [];
            foreach (explode(',', (string) $status) as $name) {
                $code = Writer::code(Conversation::$statuses, trim($name));
                if ($code) {
                    $codes[] = $code;
                }
            }
            if ($codes) {
                $query->whereIn('conversations.status', $codes);
            }
        }
        if ($state = Writer::code(Conversation::$states, $this->param($request, 'state'))) {
            $query->where('conversations.state', $state);
        }
        if ($type = Writer::code(Conversation::$types, $this->param($request, 'type'))) {
            $query->where('conversations.type', $type);
        }
        foreach (['assignedTo' => 'user_id', 'createdByUserId' => 'created_by_user_id', 'createdByCustomerId' => 'created_by_customer_id'] as $name => $column) {
            if ($this->hasParam($request, $name)) {
                $value = $this->param($request, $name);
                $value ? $query->where('conversations.'.$column, $value) : $query->whereNull('conversations.'.$column);
            }
        }
        if ($email = $this->param($request, 'customerEmail')) {
            $query->where('conversations.customer_email', $email);
        }
        if ($phone = \Helper::phoneToNumeric((string) $this->param($request, 'customerPhone'))) {
            $query->join('customers', 'customers.id', '=', 'conversations.customer_id')
                ->where('customers.phones', 'like', '%'.$phone.'%');
        }
        if ($customer_id = $this->param($request, 'customerId')) {
            $query->where('conversations.customer_id', $customer_id);
        }
        if ($number = $this->param($request, 'number')) {
            $query->where('conversations.'.Conversation::numberFieldName(), $number);
        }
        if ($subject = $this->param($request, 'subject')) {
            $query->where('conversations.subject', \Helper::sqlLikeOperator(), '%'.$subject.'%');
        }
        if ($since = $this->param($request, 'createdSince')) {
            $query->where('conversations.created_at', '>=', Writer::date($since));
        }
        if ($since = $this->param($request, 'updatedSince')) {
            $query->where('conversations.updated_at', '>=', Writer::date($since));
        }

        $this->sort($request, $query, [
            'createdAt'    => 'conversations.created_at',
            'mailboxId'    => 'conversations.mailbox_id',
            'number'       => 'conversations.'.Conversation::numberFieldName(),
            'subject'      => 'conversations.subject',
            'updatedAt'    => 'conversations.updated_at',
            'waitingSince' => 'conversations.last_reply_at',
        ], 'conversations.created_at');
        $query->orderBy('conversations.id', strtolower((string) $this->param($request, 'sortOrder')) == 'asc' ? 'asc' : 'desc');

        $query = \Eventy::filter('api.conversations.query', $query, $request);
        $embed = $this->embed($request, []);

        return $this->paginated($request, $query, 'conversations', function ($conversation) use ($embed) {
            return Format::conversation($conversation, $embed);
        });
    }

    /**
     * GET /api/conversations/{id}
     */
    public function show(Request $request, $id)
    {
        [$conversation, $error] = $this->findConversation($request, $id);
        if ($error) {
            return $error;
        }

        return response()->json(Format::conversation($conversation, $this->embed($request, ['threads'])));
    }

    /**
     * POST /api/conversations
     */
    public function store(Request $request)
    {
        foreach (['type', 'mailboxId', 'subject', 'customer', 'threads'] as $name) {
            if (!$this->param($request, $name)) {
                return $this->required($name);
            }
        }
        $threads = $this->param($request, 'threads');
        if (!is_array($threads) || !array_is_list($threads)) {
            return $this->error('`threads` must be an array of threads', 'threads');
        }

        $mailbox = \Eventy::filter('api.mailbox.find', Mailbox::find($this->param($request, 'mailboxId')), $request);
        if (!$mailbox) {
            return $this->error('Mailbox not found', 'mailboxId');
        }
        if (!$this->access()->canMailbox($mailbox->id)) {
            return $this->forbiddenMailbox();
        }
        [$customer, $error] = Writer::resolveCustomer($this->param($request, 'customer'));
        if ($error) {
            return $this->writerError($error);
        }

        $imported = (bool) $this->param($request, 'imported', false);
        $status = Writer::code(Conversation::$statuses, $this->param($request, 'status'), null);

        $conversation = new Conversation();
        $conversation->type = Writer::code(Conversation::$types, $this->param($request, 'type'), Conversation::TYPE_EMAIL);
        $conversation->subject = (string) $this->param($request, 'subject');
        $conversation->mailbox_id = $mailbox->id;
        $conversation->source_type = Conversation::SOURCE_TYPE_API;
        $conversation->source_via = Conversation::PERSON_CUSTOMER;
        $conversation->customer_id = $customer->id;
        $conversation->customer_email = $customer->getMainEmail();
        $conversation->status = $status ?: Conversation::STATUS_ACTIVE;
        $conversation->state = Writer::code(Conversation::$states, $this->param($request, 'state'), Conversation::STATE_PUBLISHED);
        $conversation->imported = $imported;
        $assignee = $this->param($request, 'assignTo') ? User::find($this->param($request, 'assignTo')) : null;
        if ($assignee) {
            $conversation->user_id = $assignee->id;
        }
        if ($imported && $this->param($request, 'createdAt')) {
            $conversation->created_at = Writer::date($this->param($request, 'createdAt'));
        }
        if ($imported && $this->param($request, 'closedAt')) {
            $conversation->closed_at = Writer::date($this->param($request, 'closedAt'));
        }
        $conversation->updateFolder();
        $conversation->save();

        // Threads come newest first.
        $created = 0;
        $last_error = null;
        foreach (array_reverse($threads) as $thread_data) {
            if (!is_array($thread_data)) {
                continue;
            }
            if ($imported) {
                $thread_data['imported'] = true;
            }
            if ($status && !isset($thread_data['status'])) {
                $thread_data['status'] = Conversation::$statuses[$status];
            }
            [$thread, $last_error] = Writer::createThread($conversation->fresh(), $thread_data, $this->access());
            if ($thread) {
                $created++;
            }
        }
        if (!$created) {
            $conversation->deleteForever();

            return $this->writerError($last_error ?: ['`threads` parameter is required', 'threads', 400]);
        }

        // A thread by another customer doesn't change the conversation's customer.
        $conversation = $conversation->fresh();
        if ($conversation->customer_id != $customer->id) {
            $conversation->customer_id = $customer->id;
            $conversation->customer_email = $customer->getMainEmail();
            $conversation->save();
        }
        $mailbox->updateFoldersCounters();

        return $this->created(Format::conversation($conversation->fresh(), ['threads']), $conversation->id);
    }

    /**
     * PUT /api/conversations/{id}: changes in the order given.
     */
    public function update(Request $request, $id)
    {
        [$conversation, $error] = $this->findConversation($request, $id);
        if ($error) {
            return $error;
        }

        $by_user = null;
        foreach (['status', 'assignTo', 'mailboxId'] as $name) {
            if ($this->hasParam($request, $name)) {
                $by_user_id = $this->param($request, 'byUser');
                if (!$by_user_id) {
                    return $this->error('byUser parameter is required.', 'byUser');
                }
                $by_user = User::find($by_user_id);
                if (!$by_user || $by_user->isDeleted()) {
                    return $this->error('User not found.', 'byUser');
                }
                if (!$this->access()->canActAs($by_user->id)) {
                    return $this->forbidden('Forbidden: API key can only act as its owner');
                }
                break;
            }
        }
        if (!$by_user && $this->param($request, 'byUser')) {
            $by_user = User::find($this->param($request, 'byUser'));
        }

        foreach (array_keys($request->all()) as $name) {
            switch (\Str::camel($name)) {
                case 'status':
                    $status = Writer::code(Conversation::$statuses, $this->param($request, 'status'));
                    if (!$status) {
                        return $this->error('Unknown status', 'status');
                    }
                    if ($status != $conversation->status) {
                        $conversation->changeStatus($status, $by_user);
                    }
                    break;

                case 'assignTo':
                    $user_id = (int) $this->param($request, 'assignTo') ?: Conversation::USER_UNASSIGNED;
                    if ($user_id != ($conversation->user_id ?: Conversation::USER_UNASSIGNED)) {
                        $conversation->changeUser($user_id, $by_user);
                    }
                    break;

                case 'mailboxId':
                    $mailbox = Mailbox::find($this->param($request, 'mailboxId'));
                    if (!$mailbox) {
                        return $this->error('Mailbox not found', 'mailboxId');
                    }
                    if (!$this->access()->canMailbox($mailbox->id)) {
                        return $this->forbiddenMailbox();
                    }
                    if ($mailbox->id != $conversation->mailbox_id) {
                        $conversation->moveToMailbox($mailbox, $by_user);
                    }
                    break;

                case 'customerId':
                    $customer = Customer::find($this->param($request, 'customerId'));
                    if (!$customer) {
                        return $this->error('Customer not found', 'customerId');
                    }
                    $conversation->changeCustomer($customer->getMainEmail(), $customer, $by_user);
                    break;

                case 'subject':
                    $conversation->subject = (string) $this->param($request, 'subject');
                    $conversation->save();
                    break;
            }
            $conversation = $conversation->fresh();
        }

        return $this->noContent();
    }

    /**
     * DELETE /api/conversations/{id}: deleted for good.
     */
    public function destroy(Request $request, $id)
    {
        [$conversation, $error] = $this->findConversation($request, $id);
        if ($error) {
            return $error;
        }
        $mailbox = $conversation->mailbox;
        $conversation->deleteForever();
        if ($mailbox) {
            $mailbox->updateFoldersCounters();
        }

        return $this->noContent();
    }

    /**
     * POST /api/conversations/{id}/threads
     */
    public function storeThread(Request $request, $id)
    {
        [$conversation, $error] = $this->findConversation($request, $id);
        if ($error) {
            return $error;
        }
        [$thread, $error] = Writer::createThread($conversation, $request->all(), $this->access());
        if ($error) {
            return $this->writerError($error);
        }

        return $this->created(Format::thread($thread->fresh(), $conversation->fresh()), $thread->id);
    }

    /**
     * [conversation, null], or [null, error response].
     */
    protected function findConversation(Request $request, $id)
    {
        $conversation = \Eventy::filter('api.conversation.find', Conversation::find($id), $request);
        if (!$conversation) {
            return [null, $this->notFound()];
        }
        if (!$this->access()->canMailbox($conversation->mailbox_id)) {
            return [null, $this->forbiddenMailbox()];
        }
        if (!$this->access()->canConversation($conversation)) {
            return [null, $this->forbiddenConversation()];
        }

        return [$conversation, null];
    }

    /**
     * What to embed: ?embed=threads (other values are for modules Tallport
     * doesn't have).
     */
    protected function embed(Request $request, $default)
    {
        if (!$request->has('embed')) {
            return $default;
        }

        return array_map('trim', explode(',', (string) $request->input('embed')));
    }
}
