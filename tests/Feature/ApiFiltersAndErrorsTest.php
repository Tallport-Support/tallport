<?php

namespace Tests\Feature;

use App\Api\ApiKey;
use App\Api\Writer;
use App\Conversation;
use App\Customer;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * The REST API's filters, and its answers to input it can't use or keys that
 * may not do something.
 */
class ApiFiltersAndErrorsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Agent']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support']);
    }

    protected function api($method, $uri, $data = [], $key = null)
    {
        return $this->json($method, '/api'.$uri, $data, ['X-FreeScout-API-Key' => $key ?? ApiKey::globalKey()]);
    }

    protected function conversation($subject = 'Question', $from = 'Casey Customer <casey@customer.example.org>')
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => $from, 'to' => $this->mailbox->email, 'subject' => $subject]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function userKey(User $user, $ability = ApiKey::ABILITY_WRITE)
    {
        return ApiKey::generate($user, 'Test', $ability)[1];
    }

    protected function ids($response, $plural = 'conversations')
    {
        return collect($response->assertOk()->json('_embedded.'.$plural))->pluck('id')->sort()->values()->all();
    }

    protected function assertError($response, $message, $status = 400)
    {
        $response->assertStatus($status);
        if ($status == 400) {
            $response->assertJsonPath('_embedded.errors.0.message', $message);
        } else {
            $response->assertJsonPath('message', $message);
        }
    }

    public function testConversationFilters()
    {
        $old = $this->conversation('Old', 'Casey Customer <casey@customer.example.org>');
        $phoned = $this->conversation('Phoned', 'Pat Phone <pat@customer.example.org>');
        $phoned->customer->setPhones([['value' => '+31 20 555 0199', 'type' => Customer::PHONE_TYPE_WORK]]);
        $phoned->customer->save();
        $phoned->type = Conversation::TYPE_PHONE;
        $phoned->user_id = $this->agent->id;
        $phoned->updateFolder();
        $phoned->save();
        Conversation::where('id', $old->id)->update(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);

        $this->assertSame([$phoned->id], $this->ids($this->api('GET', '/conversations?type=phone')));
        $this->assertSame([$old->id], $this->ids($this->api('GET', '/conversations?assignedTo=')), 'Unassigned.');
        $this->assertSame([$phoned->id], $this->ids($this->api('GET', '/conversations?customerPhone=205550199')));
        $this->assertSame([$old->id], $this->ids($this->api('GET', '/conversations?customerId='.$old->customer_id)));
        $this->assertSame([$phoned->id], $this->ids($this->api('GET', '/conversations?createdSince='.urlencode(now()->subDay()->toIso8601String()))));
        $this->assertSame([$phoned->id], $this->ids($this->api('GET', '/conversations?updatedSince='.urlencode(now()->subDay()->toIso8601String()))));
        $this->assertSame([$old->id], $this->ids($this->api('GET', '/conversations?folderId='.$old->folder_id)));
        $this->assertSame([], $this->ids($this->api('GET', '/conversations?state=deleted')));
        $this->assertSame([$old->id, $phoned->id], $this->ids($this->api('GET', '/conversations?state=published')));
    }

    /**
     * A user who sees only conversations assigned to them: so does their key.
     */
    public function testOnlyAssignedConversations()
    {
        $this->agent->permissions = [User::PERM_ONLY_ASSIGNED_TICKETS => true];
        $this->agent->save();
        $mine = $this->conversation('Mine');
        $mine->user_id = $this->agent->id;
        $mine->save();
        $other = $this->conversation('Other');
        $key = $this->userKey($this->agent);

        $this->assertSame([$mine->id], $this->ids($this->api('GET', '/conversations', [], $key)));
        $this->assertError($this->api('GET', '/conversations/'.$other->id, [], $key), 'Forbidden: API key owner is not permitted to access this conversation', 403);
        $this->assertError($this->api('PUT', '/conversations/'.$other->id, ['subject' => 'x'], $key), 'Forbidden: API key owner is not permitted to access this conversation', 403);
    }

    public function testCreatingConversations()
    {
        $base = ['type' => 'email', 'mailboxId' => $this->mailbox->id, 'subject' => 'From the API', 'customer' => ['email' => 'new@customer.example.org']];

        $this->assertError($this->api('POST', '/conversations', $base + ['threads' => ['type' => 'customer', 'text' => 'x']]), '`threads` must be an array of threads');
        $this->assertError($this->api('POST', '/conversations', array_merge($base, ['customer' => ['id' => 999999], 'threads' => [['type' => 'customer', 'text' => 'x']]])), 'Customer not found');
        $hidden = $this->createMailbox([], ['name' => 'Hidden']);
        $this->assertError($this->api('POST', '/conversations', array_merge($base, ['mailboxId' => $hidden->id, 'threads' => [['type' => 'customer', 'text' => 'x']]]), $this->userKey($this->agent)),
        'Forbidden: Provided API key is not permitted to access this mailbox', 403);
        $this->assertSame(0, Conversation::count());

        // Imported, with its dates; its status for each thread; a thread by someone else.
        $other = $this->createCustomer('other@customer.example.org');
        $response = $this->api('POST', '/conversations', array_merge($base, [
            'imported' => true, 'createdAt' => '2024-03-01T10:00:00Z', 'closedAt' => '2024-03-02T10:00:00Z', 'status' => 'closed',
            'threads'  => [
                ['type' => 'message', 'text' => 'Answer', 'user' => $this->agent->id],
                'not a thread',
                ['type' => 'customer', 'text' => 'Question', 'customer' => ['id' => $other->id]],
            ],
        ]))->assertStatus(201);

        $conversation = Conversation::find($response->headers->get('Resource-ID'));
        $this->assertSame('new@customer.example.org', $conversation->customer_email, 'Still the conversation\'s customer.');
        $this->assertSame('2024-03-01', $conversation->created_at->setTimezone('UTC')->format('Y-m-d'));
        $this->assertNotNull($conversation->closed_at);
        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->status);
        $threads = $conversation->threads()->orderBy('id')->get();
        $this->assertSame([Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE], $threads->pluck('type')->map('intval')->all());
        $this->assertSame($other->id, $threads[0]->customer_id);
        $this->assertTrue((bool) $threads[0]->imported);
    }

    public function testChangingConversations()
    {
        $conversation = $this->conversation();
        $uri = '/conversations/'.$conversation->id;

        $this->assertError($this->api('PUT', $uri, ['byUser' => 999999, 'status' => 'closed']), 'User not found.');
        $this->assertError($this->api('PUT', $uri, ['byUser' => $this->agent->id, 'status' => 'shelved']), 'Unknown status');
        $this->assertError($this->api('PUT', $uri, ['byUser' => $this->agent->id, 'mailboxId' => 999999]), 'Mailbox not found');
        $hidden = $this->createMailbox([], ['name' => 'Hidden']);
        $this->assertError($this->api('PUT', $uri, ['byUser' => $this->agent->id, 'mailboxId' => $hidden->id], $this->userKey($this->agent)),
        'Forbidden: Provided API key is not permitted to access this mailbox', 403);
        $this->assertError($this->api('PUT', $uri, ['customerId' => 999999]), 'Customer not found');
        $this->assertSame($this->mailbox->id, $conversation->fresh()->mailbox_id);

        // byUser without a change that needs one: who did it.
        $this->api('PUT', $uri, ['byUser' => $this->agent->id, 'customerId' => $this->createCustomer('robin@buyer.example.org')->id])->assertStatus(204);
        $this->assertSame('robin@buyer.example.org', $conversation->fresh()->customer_email);
        $line = $conversation->threads()->where('action_type', Thread::ACTION_TYPE_CUSTOMER_CHANGED)->first();
        $this->assertSame($this->agent->id, $line->created_by_user_id);

        foreach ([['DELETE', ''], ['POST', '/threads'], ['GET', '']] as [$method, $suffix]) {
            $this->api($method, '/conversations/999999'.$suffix, ['type' => 'note', 'text' => 'x', 'user' => $this->agent->id])->assertStatus(404);
        }
    }

    public function testAddingThreads()
    {
        $conversation = $this->conversation();
        $uri = '/conversations/'.$conversation->id.'/threads';
        $deleted = $this->createUser(['status' => User::STATUS_DELETED]);

        $this->assertError($this->api('POST', $uri, ['text' => 'x']), '`type` parameter is required');
        $this->assertError($this->api('POST', $uri, ['type' => 'note']), '`text` parameter is required');
        $this->assertError($this->api('POST', $uri, ['type' => 'note', 'text' => 'x', 'user' => $deleted->id]), 'User not found');
        $this->assertError($this->api('POST', $uri, ['type' => 'customer', 'text' => 'x', 'customer' => ['lastName' => 'Nameless']]), 'Customer first name or email is required.');

        // A note that changes the status; a reply to more people; files without a name are left out.
        $this->api('POST', $uri, ['type' => 'note', 'text' => 'Done', 'user' => $this->agent->id, 'status' => 'pending'])->assertStatus(201);
        $this->assertSame(Conversation::STATUS_PENDING, $conversation->fresh()->status);
        $response = $this->api('POST', $uri, [
            'type' => 'message', 'text' => 'Answer', 'user' => $this->agent->id, 'to' => ['casey@customer.example.org', 'boss@customer.example.org'],
            'attachments' => [['mimeType' => 'text/plain', 'data' => base64_encode('x')], 'not a file'],
        ])->assertStatus(201);
        $reply = Thread::find($response->headers->get('Resource-ID'));
        $this->assertSame(['casey@customer.example.org', 'boss@customer.example.org'], $reply->getToArray());
        $this->assertFalse((bool) $reply->has_attachments);

        // Zapier sends the customer as a list; a phone finds one.
        $phoned = $this->createCustomer('pat@customer.example.org', ['first_name' => 'Pat']);
        $phoned->setPhones([['value' => '+31 20 555 0199', 'type' => Customer::PHONE_TYPE_WORK]]);
        $phoned->save();
        $response = $this->api('POST', $uri, ['type' => 'customer', 'text' => 'Me too', 'customer' => [['phone' => '+31 20 555 0199']]])->assertStatus(201);
        $this->assertSame($phoned->id, Thread::find($response->headers->get('Resource-ID'))->customer_id);
        $response = $this->api('POST', $uri, ['type' => 'customer', 'text' => 'Again', 'customer' => ['id' => $phoned->id]])->assertStatus(201);
        $this->assertSame($phoned->id, Thread::find($response->headers->get('Resource-ID'))->customer_id);
        $response = $this->api('POST', $uri, ['type' => 'customer', 'text' => 'By email', 'customer' => ['email' => 'PAT@customer.example.org']])->assertStatus(201);
        $this->assertSame($phoned->id, Thread::find($response->headers->get('Resource-ID'))->customer_id);

        // A conversation without a customer needs one given.
        Conversation::where('id', $conversation->id)->update(['customer_id' => null]);
        $this->assertError($this->api('POST', $uri, ['type' => 'customer', 'text' => 'Who?']), '`customer` parameter is required');
    }

    public function testWriterValues()
    {
        $this->assertSame('x', Writer::get(null, 'name', 'x'));
        $this->assertSame(Conversation::STATUS_PENDING, Writer::code(Conversation::$statuses, (string) Conversation::STATUS_PENDING));
        $this->assertSame(Conversation::STATUS_PENDING, Writer::code(Conversation::$statuses, 'PENDING'));
        $this->assertNull(Writer::code(Conversation::$statuses, 'shelved'));
        $this->assertNull(Writer::date('not a date'));
        $this->assertSame([null, ['`customer` parameter is required', 'customer', 400]], Writer::resolveCustomer('casey'));
    }

    public function testCustomers()
    {
        $conversation = $this->conversation();
        $outsider = $this->createCustomer('outsider@customer.example.org', ['first_name' => 'Olli']);
        \DB::table('customers')->where('id', $outsider->id)->update(['updated_at' => now()->subDays(5)]);
        $key = $this->userKey($this->agent);

        // A user's key: its mailboxes' customers.
        $this->assertSame([$conversation->customer_id], $this->ids($this->api('GET', '/customers', [], $key), 'customers'));
        $this->assertSame([$outsider->id], $this->ids($this->api('GET', '/customers?firstName=Olli'), 'customers'));
        $recent = $this->ids($this->api('GET', '/customers?updatedSince='.urlencode(now()->subDay()->toIso8601String())), 'customers');
        $this->assertContains($conversation->customer_id, $recent);
        $this->assertNotContains($outsider->id, $recent);
        $this->api('GET', '/customers/'.$outsider->id, [], $key)->assertOk();
        // Customers limited to the user's mailboxes (app.limit_user_customer_visibility).
        config(['app.limit_user_customer_visibility' => true]);
        $this->assertError($this->api('GET', '/customers/'.$outsider->id, [], $key), 'Forbidden: API key owner is not permitted to access this customer', 403);
        config(['app.limit_user_customer_visibility' => false]);

        $this->assertError($this->api('PUT', '/customers/'.$conversation->customer_id, ['emails' => ['not an email']]), 'Invalid email: not an email');
        $this->api('PUT', '/customers/999999', ['firstName' => 'Nobody'])->assertStatus(404);

        // Photo type, a phone, the address as one line or as parts.
        $this->api('PUT', '/customers/'.$conversation->customer_id, [
            'photoType' => 'gravatar', 'phone' => '+31 20 555 0100', 'address' => 'Main Street 1', 'city' => 'Utrecht', 'country' => 'nl',
        ])->assertStatus(204);
        $customer = $conversation->customer->fresh();
        $this->assertSame(Customer::PHOTO_TYPE_GRAVATAR, (int) $customer->photo_type);
        $this->assertSame('+31 20 555 0100', $customer->getPhones()[0]['value']);
        $this->assertSame(['Main Street 1', 'Utrecht', 'NL'], [$customer->address, $customer->city, $customer->country]);
        $this->api('PUT', '/customers/'.$conversation->customer_id, ['address' => ['address' => 'Side Street 2']])->assertStatus(204);
        $this->assertSame('Side Street 2', $customer->fresh()->address);
    }

    public function testMailboxes()
    {
        $other = $this->createMailbox([], ['name' => 'Sales']);
        $admin = $this->createAdmin();

        $this->assertSame([$this->mailbox->id], $this->ids($this->api('GET', '/mailboxes?userId='.$this->agent->id), 'mailboxes'));
        $this->assertSame([$this->mailbox->id, $other->id], $this->ids($this->api('GET', '/mailboxes?userId='.$admin->id), 'mailboxes'));
        $this->assertError($this->api('GET', '/mailboxes?userId=999999'), 'User not found');

        $this->assertError($this->api('GET', '/mailboxes/'.$other->id.'/folders', [], $this->userKey($this->agent)), 'Forbidden: Provided API key is not permitted to access this mailbox', 403);
        $mine = $this->mailbox->folders()->where('type', \App\Folder::TYPE_MINE)->where('user_id', $this->agent->id)->first();
        $folders = $this->api('GET', '/mailboxes/'.$this->mailbox->id.'/folders?userId='.$this->agent->id)->json('_embedded.folders');
        $this->assertContains($mine->id, array_column($folders, 'id'));
        $this->assertSame([null, $this->agent->id], collect($folders)->pluck('userId')->unique()->sort()->values()->all());
        $this->assertSame([$mine->id], $this->ids($this->api('GET', '/mailboxes/'.$this->mailbox->id.'/folders?folderId='.$mine->id), 'folders'));
    }

    public function testUsers()
    {
        $this->assertError($this->api('POST', '/users', ['firstName' => 'Nova', 'email' => 'nova@example.org']), '`lastName` parameter is required');
        $this->assertError($this->api('POST', '/users', ['firstName' => 'Nova', 'lastName' => 'New', 'email' => 'not an email']), 'Invalid email: not an email');
        $this->assertError($this->api('POST', '/users', ['firstName' => 'Nova', 'lastName' => 'New', 'email' => $this->mailbox->email]), 'There is a mailbox with such email');

        $response = $this->api('POST', '/users', ['firstName' => 'Nova', 'lastName' => 'New', 'email' => 'nova@example.org', 'password' => 'secret-password', 'jobTitle' => 'Agent'])->assertStatus(201);
        $nova = User::find($response->headers->get('Resource-ID'));
        $this->assertSame(User::INVITE_STATE_ACTIVATED, (int) $nova->invite_state, 'With a password: active.');
        $this->assertTrue(\Hash::check('secret-password', $nova->password));

        $admin = $this->createAdmin();
        $this->assertError($this->api('POST', '/users', ['firstName' => 'A', 'lastName' => 'B', 'email' => 'ab@example.org'], $this->userKey($this->agent)),
        'Forbidden: This action requires an API Key owned by an administrator', 403);
        $this->assertError($this->api('DELETE', '/users/'.$nova->id.'?byUserId='.$admin->id, [], $this->userKey($this->agent)),
        'Forbidden: This action requires an API Key owned by an administrator', 403);
        $this->api('DELETE', '/users/999999?byUserId='.$admin->id)->assertStatus(404);
        $this->assertError($this->api('DELETE', '/users/'.$admin->id.'?byUserId='.$admin->id), 'The only administrator can not be deleted');
        $this->api('DELETE', '/users/'.$nova->id.'?byUserId='.$admin->id)->assertStatus(204);
        $this->assertError($this->api('DELETE', '/users/'.$nova->id.'?byUserId='.$admin->id), 'User is already deleted');
    }

    public function testWebhooksNeedAnAdministratorAndValidInput()
    {
        $user_key = $this->userKey($this->agent);
        $forbidden = 'Forbidden: This action requires an API Key owned by an administrator';

        $this->assertError($this->api('POST', '/webhooks', ['url' => 'https://hooks.example.org', 'events' => ['convo.created']], $user_key), $forbidden, 403);
        $this->assertError($this->api('POST', '/webhooks', ['events' => ['convo.created']]), '`url` parameter is required');
        $this->assertError($this->api('POST', '/webhooks', ['url' => 'https://hooks.example.org']), '`events` parameter is required');
        $response = $this->api('POST', '/webhooks', ['url' => 'https://hooks.example.org', 'events' => 'convo.nonsense'])->assertStatus(400);
        $this->assertStringStartsWith('Unknown events. Allowed: ', $response->json('_embedded.errors.0.message'));
        $this->assertSame(0, \App\Api\Webhook::count());

        $id = $this->api('POST', '/webhooks', ['url' => 'https://hooks.example.org', 'events' => 'convo.created'])->assertStatus(201)->headers->get('Resource-ID');
        $this->assertError($this->api('DELETE', '/webhooks/'.$id, [], $user_key), $forbidden, 403);
        $this->api('DELETE', '/webhooks/999999')->assertStatus(404);
        $this->assertNotNull(\App\Api\Webhook::find($id));
    }

    public function testReportsNeedAnAdministrator()
    {
        $forbidden = 'Forbidden: This action requires an API Key owned by an administrator';

        $this->assertError($this->api('GET', '/reports/conversations', [], $this->userKey($this->agent)), $forbidden, 403);
        // The global key: reports are made for the first administrator, and there is none.
        $this->assertError($this->api('GET', '/reports/conversations'), $forbidden, 403);
        $this->createAdmin();
        $this->api('GET', '/reports/conversations')->assertOk();
    }

    /**
     * A conversation an agent started: created by the user; one whose customer is gone: by nobody.
     */
    public function testWhoCreatedAConversation()
    {
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'to' => ['casey@customer.example.org'],
            'subject' => 'Started by us', 'body' => '<p>Hello</p>', 'is_create' => 1,
        ])->assertJsonPath('status', 'success');
        $conversation = Conversation::where('subject', 'Started by us')->first();

        $this->api('GET', '/conversations/'.$conversation->id)->assertJsonPath('createdBy.id', $this->agent->id)->assertJsonPath('createdBy.type', 'user');

        $received = $this->conversation('From a customer');
        Conversation::where('id', $received->id)->update(['created_by_customer_id' => 999999]);
        $this->api('GET', '/conversations/'.$received->id)->assertJsonPath('createdBy', null);
    }

    /**
     * A reply that closes the conversation is a status change, which workflows and
     * webhooks hear of.
     */
    public function testAReplyThatClosesIsAStatusChange()
    {
        $changes = [];
        \Eventy::addAction('conversation.status_changed', function ($conversation, $user, $changed_on_reply, $previous) use (&$changes) {
            $changes[] = [$conversation->id, $user->id, $previous];
        }, 20, 4);
        $conversation = $this->conversation();
        $this->api('POST', '/conversations/'.$conversation->id.'/threads', ['type' => 'customer', 'text' => 'Any news?'])->assertStatus(201);

        $this->api('POST', '/conversations/'.$conversation->id.'/threads', ['type' => 'message', 'text' => 'Done', 'user' => $this->agent->id, 'status' => 'closed'])->assertStatus(201);

        $this->assertSame([[$conversation->id, $this->agent->id, Conversation::STATUS_ACTIVE]], $changes);
    }

    /**
     * Also when the customer's message is all the conversation had.
     */
    public function testTheFirstReplyThatClosesIsAStatusChange()
    {
        $this->knownBug('W3');

        $changes = [];
        \Eventy::addAction('conversation.status_changed', function ($conversation) use (&$changes) {
            $changes[] = $conversation->id;
        }, 20, 4);
        $conversation = $this->conversation();

        $this->api('POST', '/conversations/'.$conversation->id.'/threads', ['type' => 'message', 'text' => 'Done', 'user' => $this->agent->id, 'status' => 'closed'])->assertStatus(201);

        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);
        $this->assertSame([$conversation->id], $changes);
    }
}
