<?php

namespace Tests\Feature;

use App\Api\ApiKey;
use App\Conversation;
use App\Customer;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * The REST API: keys, conversations, threads, customers, users, mailboxes.
 */
class ApiTest extends FeatureTestCase
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

    protected function conversation($subject = 'Question', $body = 'Where is my order?')
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => $subject, 'body' => $body,
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function userKey(User $user, $ability = ApiKey::ABILITY_WRITE, $mailboxes = null)
    {
        return ApiKey::generate($user, 'Test', $ability, $mailboxes)[1];
    }

    public function testKeys()
    {
        $this->json('GET', '/api/mailboxes')->assertStatus(401)->assertExactJson(['message' => 'Not Authorized']);
        $this->api('GET', '/mailboxes', [], 'wrong')->assertStatus(401);
        $this->api('GET', '/mailboxes')->assertOk()->assertJsonPath('_embedded.mailboxes.0.name', 'Support');
        $this->json('GET', '/api/mailboxes?api_key='.ApiKey::globalKey())->assertOk();
        $this->json('GET', '/api/mailboxes', [], ['Authorization' => 'Bearer '.ApiKey::globalKey()])->assertOk();

        // The global key is derived from the application key and a salt.
        config(['api.key_salt' => 'other']);
        $this->api('GET', '/mailboxes', [], md5(config('app.key').'api_keyother'))->assertOk();

        $read = $this->userKey($this->agent, ApiKey::ABILITY_READ);
        $this->assertMatchesRegularExpression('/^fs_[0-9a-f]{40}$/', $read);
        $this->api('GET', '/users/me', [], $read)->assertOk()->assertJsonPath('email', $this->agent->email);
        $this->api('POST', '/customers', ['firstName' => 'Robin'], $read)->assertStatus(403)
            ->assertExactJson(['message' => 'Provided API key allows only read-operations.']);
        $this->assertNotNull(ApiKey::where('user_id', $this->agent->id)->value('last_used_at'));
        $this->api('GET', '/users/me')->assertStatus(501);

        $this->agent->status = User::STATUS_DELETED;
        $this->agent->save();
        $this->api('GET', '/users/me', [], $read)->assertStatus(401);
    }

    public function testCors()
    {
        config(['cors.allowed_origins' => ['https://app.example.org']]);

        $this->call('OPTIONS', '/api/conversations', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.example.org', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://app.example.org');
        $this->json('OPTIONS', '/api/conversations', [], ['X-FreeScout-API-Key' => ApiKey::globalKey()])->assertOk();
    }

    public function testListAndShowConversations()
    {
        $first = $this->conversation('First');
        $second = $this->conversation('Second');
        $second->setStatus(Conversation::STATUS_CLOSED);
        $second->save();

        $response = $this->api('GET', '/conversations?pageSize=1')->assertOk();
        $response->assertJsonPath('page', ['size' => 1, 'totalElements' => 2, 'totalPages' => 2, 'number' => 1]);
        $response->assertJsonPath('_embedded.conversations.0.id', $second->id);
        $this->assertSame([], $response->json('_embedded.conversations.0._embedded.threads'), 'Lists embed threads only when asked.');

        $this->api('GET', '/conversations?status=active')->assertJsonPath('_embedded.conversations.0.id', $first->id)->assertJsonCount(1, '_embedded.conversations');
        $this->api('GET', '/conversations?status=active,closed&sortField=createdAt&sortOrder=asc')->assertJsonPath('_embedded.conversations.0.id', $first->id);
        $this->api('GET', '/conversations?customerEmail=casey@customer.example.org&embed=threads')->assertJsonCount(2, '_embedded.conversations')
            ->assertJsonPath('_embedded.conversations.0._embedded.threads.0.type', 'customer');
        $this->api('GET', '/conversations?subject=Sec')->assertJsonCount(1, '_embedded.conversations');
        $this->api('GET', '/conversations?assignedTo=')->assertJsonCount(2, '_embedded.conversations');
        $this->api('GET', '/conversations?mailboxId='.$this->mailbox->id.'&number='.$first->number)->assertJsonCount(1, '_embedded.conversations');
        $this->api('GET', '/conversations?tag=x')->assertStatus(400);

        $conversation = $this->api('GET', '/conversations/'.$first->id)->assertOk()->json();
        $this->assertSame('First', $conversation['subject']);
        $this->assertSame('active', $conversation['status']);
        $this->assertSame('casey@customer.example.org', $conversation['customer']['email']);
        $this->assertSame('Where is my order?', trim(strip_tags($conversation['_embedded']['threads'][0]['body'])));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $conversation['createdAt']);

        $this->api('GET', '/conversations/999999')->assertStatus(404)->assertJsonPath('message', 'Not Found');
    }

    public function testCreateConversationWithThreads()
    {
        $response = $this->api('POST', '/conversations', [
            'type'      => 'email',
            'mailboxId' => $this->mailbox->id,
            'subject'   => 'From the API',
            'customer'  => ['email' => 'new@customer.example.org', 'firstName' => 'Newton'],
            'assignTo'  => $this->agent->id,
            'threads'   => [
                ['type' => 'note', 'text' => 'Second: a note', 'user' => $this->agent->id],
                ['type' => 'customer', 'text' => 'First: the question', 'attachments' => [
                    ['fileName' => 'hello.txt', 'mimeType' => 'text/plain', 'data' => base64_encode('Hello')],
                ]],
            ],
        ])->assertStatus(201);

        $conversation = Conversation::find($response->headers->get('Resource-ID'));
        $this->assertSame('From the API', $conversation->subject);
        $this->assertSame(Conversation::SOURCE_TYPE_API, (int) $conversation->source_type);
        $this->assertSame($this->agent->id, $conversation->user_id);
        $this->assertSame('Newton', $conversation->customer->first_name);
        $threads = $conversation->threads()->orderBy('id')->get();
        $this->assertSame([Thread::TYPE_CUSTOMER, Thread::TYPE_NOTE], $threads->pluck('type')->map('intval')->all(), 'Oldest first.');
        $this->assertTrue((bool) $threads[0]->has_attachments);
        $this->assertSame('hello.txt', $response->json('_embedded.threads.1._embedded.attachments.0.fileName'));

        $this->api('POST', '/conversations', ['type' => 'email'])->assertStatus(400)
            ->assertJsonPath('_embedded.errors.0.message', '`mailboxId` parameter is required');
        $this->api('POST', '/conversations', ['type' => 'email', 'mailboxId' => 999999, 'subject' => 'x', 'customer' => ['email' => 'a@b.example'], 'threads' => [['type' => 'customer', 'text' => 'x']]])
            ->assertStatus(400)->assertJsonPath('_embedded.errors.0.message', 'Mailbox not found');
        $this->api('POST', '/conversations', ['type' => 'email', 'mailboxId' => $this->mailbox->id, 'subject' => 'x', 'customer' => ['email' => 'b@b.example'], 'threads' => [['type' => 'message', 'text' => 'x']]])
            ->assertStatus(400)->assertJsonPath('_embedded.errors.0.message', '`user` parameter is required');
        $this->assertSame(1, Conversation::where('subject', 'x')->count() + 1, 'A conversation without threads is removed.');
    }

    public function testRemoteAttachmentUrlsAreRejected()
    {
        Http::fake();

        $this->api('POST', '/conversations', [
            'type' => 'email', 'mailboxId' => $this->mailbox->id, 'subject' => 'Remote attachment',
            'customer' => ['email' => 'remote-attachment@example.org'],
            'threads' => [
                ['type' => 'customer', 'text' => 'A valid message'],
                ['type' => 'customer', 'text' => 'A file', 'attachments' => [
                    ['fileName' => 'file.txt', 'mimeType' => 'text/plain', 'fileUrl' => 'https://example.org/file.txt'],
                ]],
            ],
        ])->assertStatus(400)->assertJsonPath('_embedded.errors.0.message', 'Attachment URLs are not supported; send the file in `data`');
        $this->assertSame(0, Conversation::where('subject', 'Remote attachment')->count());
        $this->assertNull(Customer::getByEmail('remote-attachment@example.org'));

        $conversation = $this->conversation();
        $thread_count = $conversation->threads()->count();
        $this->api('POST', '/conversations/'.$conversation->id.'/threads', [
            'type' => 'customer', 'text' => 'A file', 'attachments' => [
                ['fileName' => 'file.txt', 'mimeType' => 'text/plain', 'file_url' => 'https://example.org/file.txt'],
            ],
        ])->assertStatus(400)->assertJsonPath('_embedded.errors.0.message', 'Attachment URLs are not supported; send the file in `data`');
        $this->assertSame($thread_count, $conversation->threads()->count());
        Http::assertNothingSent();
    }

    public function testReplyAndNote()
    {
        $conversation = $this->conversation();

        $response = $this->api('POST', '/conversations/'.$conversation->id.'/threads', [
            'type' => 'message', 'text' => '<p>Our answer</p>', 'user' => $this->agent->id, 'status' => 'closed',
        ])->assertStatus(201);
        $this->assertSame('message', $response->json('type'));
        $conversation = $conversation->fresh();
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->status);
        $this->assertSame($this->agent->id, $conversation->closed_by_user_id);

        $this->api('POST', '/conversations/'.$conversation->id.'/threads', ['type' => 'note', 'text' => 'Internal', 'user' => $this->agent->id])->assertStatus(201);
        $this->api('POST', '/conversations/'.$conversation->id.'/threads', ['type' => 'customer', 'text' => 'Thanks!'])->assertStatus(201);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status, 'A customer reply opens the conversation.');
    }

    public function testUpdateAndDeleteConversation()
    {
        $conversation = $this->conversation();
        $other_mailbox = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $other_customer = $this->createCustomer('robin@buyer.example.org');

        $this->api('PUT', '/conversations/'.$conversation->id, ['status' => 'pending'])->assertStatus(400)
            ->assertJsonPath('_embedded.errors.0.message', 'byUser parameter is required.');
        $this->api('PUT', '/conversations/'.$conversation->id, [
            'byUser' => $this->agent->id, 'status' => 'pending', 'assignTo' => $this->agent->id, 'subject' => 'Changed',
            'customerId' => $other_customer->id, 'mailboxId' => $other_mailbox->id,
        ])->assertStatus(204);
        $conversation = $conversation->fresh();
        $this->assertSame(Conversation::STATUS_PENDING, (int) $conversation->status);
        $this->assertSame($this->agent->id, $conversation->user_id);
        $this->assertSame('Changed', $conversation->subject);
        $this->assertSame($other_customer->id, $conversation->customer_id);
        $this->assertSame($other_mailbox->id, $conversation->mailbox_id);

        $this->api('DELETE', '/conversations/'.$conversation->id)->assertStatus(204);
        $this->assertNull(Conversation::find($conversation->id));
    }

    public function testUserKeysActAsTheirOwnerInTheirMailboxes()
    {
        $conversation = $this->conversation();
        $hidden = $this->createMailbox([], ['name' => 'Hidden']);
        $colleague = $this->createUser();
        $key = $this->userKey($this->agent);

        $this->api('GET', '/conversations', [], $key)->assertJsonCount(1, '_embedded.conversations');
        $this->api('GET', '/conversations?mailboxId='.$hidden->id, [], $key)->assertStatus(403);
        $this->api('GET', '/mailboxes', [], $key)->assertJsonCount(1, '_embedded.mailboxes');
        $this->api('POST', '/conversations/'.$conversation->id.'/threads', ['type' => 'note', 'text' => 'x', 'user' => $colleague->id], $key)
            ->assertStatus(403);
        $this->api('PUT', '/conversations/'.$conversation->id, ['byUser' => $colleague->id, 'status' => 'closed'], $key)->assertStatus(403);
        $this->api('POST', '/conversations/'.$conversation->id.'/threads', ['type' => 'note', 'text' => 'Mine', 'user' => $this->agent->id], $key)
            ->assertStatus(201);
        $this->api('POST', '/users', ['firstName' => 'A', 'lastName' => 'B', 'email' => 'ab@example.org'], $key)->assertStatus(403);

        // Limited to one mailbox.
        $other = $this->createMailbox([$this->agent], ['name' => 'Other']);
        $limited = $this->userKey($this->agent, ApiKey::ABILITY_WRITE, [$other->id]);
        $this->api('GET', '/conversations/'.$conversation->id, [], $limited)->assertStatus(403);
        $this->api('GET', '/customers/'.$conversation->customer_id, [], $key)->assertOk();
    }

    public function testCustomers()
    {
        $response = $this->api('POST', '/customers', [
            'firstName' => 'Robin', 'lastName' => 'Buyer', 'jobTitle' => 'Buyer', 'company' => 'Shop',
            'emails' => [['value' => 'robin@buyer.example.org', 'type' => 'home']], 'phones' => [['value' => '+1 555 0100', 'type' => 'mobile']],
            'socialProfiles' => [['value' => 'robin', 'type' => 'telegram']], 'websites' => [['value' => 'https://robin.example.org']],
            'address' => ['city' => 'Utrecht', 'country' => 'nl', 'lines' => ['Street 1', 'Floor 2']],
        ])->assertStatus(201);
        $id = $response->headers->get('Resource-ID');
        $response->assertJsonPath('_embedded.emails.0.type', 'home')
            ->assertJsonPath('_embedded.phones.0.type', 'mobile')
            ->assertJsonPath('_embedded.social_profiles.0.type', 'telegram')
            ->assertJsonPath('_embedded.address', ['city' => 'Utrecht', 'state' => '', 'zip' => '', 'country' => 'NL', 'address' => 'Street 1, Floor 2']);

        $this->api('POST', '/customers', ['email' => 'robin@buyer.example.org'])->assertStatus(400)
            ->assertJsonPath('_embedded.errors.0.message', 'Customers with such email(s) already exist');
        $this->api('POST', '/customers', ['lastName' => 'Only'])->assertStatus(400);
        $this->api('POST', '/customers', ['email' => 'not an email'])->assertStatus(400);

        $this->api('GET', '/customers?email=robin@buyer.example.org')->assertJsonPath('_embedded.customers.0.id', (int) $id);
        $this->api('GET', '/customers?phone=5550100')->assertJsonCount(1, '_embedded.customers');
        $this->api('GET', '/customers/'.$id)->assertJsonPath('firstName', 'Robin');

        $this->api('PUT', '/customers/'.$id, ['firstName' => 'Robyn', 'emails' => ['robyn@buyer.example.org']])->assertStatus(204);
        $customer = Customer::find($id);
        $this->assertSame('Robyn', $customer->first_name);
        $this->assertSame(['robyn@buyer.example.org'], $customer->emails()->pluck('email')->all());
        $this->api('PUT', '/customers/'.$id, ['emailsAdd' => ['second@buyer.example.org']])->assertStatus(204);
        $this->assertCount(2, $customer->emails()->get());
        $this->api('GET', '/customers/999999')->assertStatus(404);
    }

    public function testUsers()
    {
        $this->api('GET', '/users?email='.$this->agent->email)->assertJsonPath('_embedded.users.0.firstName', 'Alex');
        $this->api('GET', '/users/'.$this->agent->id)->assertJsonPath('role', 'user');
        $this->api('GET', '/users/999999')->assertStatus(404);

        $response = $this->api('POST', '/users', ['firstName' => 'Nova', 'lastName' => 'New', 'email' => 'nova@example.org', 'role' => 'admin'])->assertStatus(201);
        $nova = User::find($response->headers->get('Resource-ID'));
        $this->assertFalse($nova->isAdmin(), 'The API makes users, not administrators.');
        $this->api('POST', '/users', ['firstName' => 'Nova', 'lastName' => 'New', 'email' => 'nova@example.org'])->assertStatus(400);

        $admin = $this->createAdmin();
        $this->api('DELETE', '/users/'.$nova->id)->assertStatus(400);
        $this->api('DELETE', '/users/'.$nova->id.'?byUserId='.$admin->id)->assertStatus(204);
        $this->assertTrue($nova->fresh()->isDeleted());
    }

    public function testFoldersAndMissingModules()
    {
        $this->api('GET', '/mailboxes/'.$this->mailbox->id.'/folders')->assertOk()->assertJsonStructure(['_embedded' => ['folders' => [['id', 'name', 'type', 'totalCount']]]]);
        $this->api('GET', '/mailboxes/999999/folders')->assertStatus(404);

        foreach ([
            ['GET', '/tags', 'Tags'], ['PUT', '/conversations/1/tags', 'Tags'],
            ['PUT', '/conversations/1/custom_fields', 'Custom Fields'], ['GET', '/mailboxes/1/custom_fields', 'Custom Fields'],
            ['PUT', '/customers/1/customer_fields', 'Customer Fields'],
            ['GET', '/conversations/1/timelogs', 'Time Tracking'], ['POST', '/conversations/1/timelogs', 'Time Tracking'], ['GET', '/timelogs', 'Time Tracking'],
        ] as [$method, $uri, $module]) {
            $this->api($method, $uri)->assertStatus(400)->assertJsonPath('_embedded.errors.0.message', $module.' module is not installed or not activated');
        }
    }

    public function testApiKeysPage()
    {
        $this->actingAs($this->agent)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('users.api_keys', ['id' => $this->agent->id]))->assertOk()->assertSee('New API Key');
        $this->post(route('users.api_keys.action', ['id' => $this->agent->id]), [
            'action' => 'create', 'name' => 'Zapier', 'ability' => ApiKey::ABILITY_WRITE, 'mailboxes' => [$this->mailbox->id],
        ])->assertRedirect();
        $key = ApiKey::where('user_id', $this->agent->id)->first();
        $this->assertSame('Zapier', $key->name);
        $this->assertSame([$this->mailbox->id], $key->mailboxes);
        $this->get(route('users.api_keys', ['id' => $this->agent->id]))->assertSee('…'.$key->token_preview);

        $this->post(route('users.api_keys.action', ['id' => $this->agent->id]), ['action' => 'revoke', 'key_id' => $key->id]);
        $this->assertNull(ApiKey::find($key->id));

        $this->get(route('users.api_keys', ['id' => $this->createUser()->id]))->assertStatus(403);
    }
}
