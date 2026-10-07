<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Folder;
use App\Http\Controllers\ConversationsController;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * What a conversation's page is given (ConversationsController::pageData()):
 * who a reply can go to, Cc, the phone form's presets, viewers and the
 * From alias; the redirects of the conversation page, the new
 * conversation page's prefills and cloning a thread with attachments.
 */
class ConversationPageDataTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveConversation(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $this->mailbox->email,
            'subject' => 'Question about my order',
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * The page's data, computed afresh (it's kept per request).
     */
    protected function pageData(Conversation $conversation, $user = null)
    {
        request()->attributes->remove('conversation_page_data');
        $this->actingAs($user ?: $this->agent);
        $conversation = Conversation::find($conversation->id);

        return ConversationsController::pageData($conversation, Folder::find($conversation->folder_id), $user ?: $this->agent);
    }

    protected function toEmails(array $data)
    {
        return array_values(array_column($data['to_customers'], 'email'));
    }

    protected function addThread(Conversation $conversation, array $attributes)
    {
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_CUSTOMER;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->source_via = Thread::PERSON_CUSTOMER;
        $thread->source_type = Thread::SOURCE_TYPE_EMAIL;
        $thread->body = 'Body';
        foreach ($attributes as $name => $value) {
            $thread->$name = $value;
        }
        $thread->save();

        return $thread;
    }

    protected function storedAttachment(Thread $thread, $name = 'notes.txt')
    {
        return Attachment::create($name, 'text/plain', null, 'Some notes', null, false, $thread->id);
    }

    // Recipients.

    public function testAReplyCanGoToEveryoneWhoWroteIn()
    {
        $conversation = $this->receiveConversation();
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        $this->addThread($conversation, ['customer_id' => $robin->id, 'from' => 'robin@customer.example.org', 'created_at' => now()->addMinute()]);

        $data = $this->pageData($conversation);

        $this->assertSame(['robin@customer.example.org', 'casey@customer.example.org'], $this->toEmails($data));
        $this->assertEquals($robin->id, $data['to_customers'][0]['customer']->id);
        // Robin wrote last: Robin is in Cc of the reply.
        $this->assertContains('robin@customer.example.org', $data['cc']);
    }

    public function testACustomerWithSeveralEmailsCanBeRepliedToAtEach()
    {
        $conversation = $this->receiveConversation();
        $conversation->customer->addEmail('casey@work.example.org', true);
        // A mailbox's own address is never offered.
        $this->addThread($conversation, ['customer_id' => $conversation->customer_id, 'from' => $this->mailbox->email, 'created_at' => now()->addMinute()]);

        $data = $this->pageData($conversation);

        $this->assertEqualsCanonicalizing(['casey@customer.example.org', 'casey@work.example.org'], $this->toEmails($data));
    }

    public function testTheOriginalAddressStaysWhenTheCustomerWasChanged()
    {
        $conversation = $this->receiveConversation();
        $casey = $conversation->customer;
        $pat = $this->createCustomer('pat@customer.example.org', ['first_name' => 'Pat']);
        \DB::table('conversations')->where('id', $conversation->id)->update(['customer_id' => $pat->id]);

        $data = $this->pageData($conversation);

        $this->assertSame(['casey@customer.example.org', 'pat@customer.example.org'], $this->toEmails($data));
        $this->assertEquals($casey->id, $data['to_customers'][0]['customer']->id);
        $this->assertEquals($pat->id, $data['to_customers'][1]['customer']->id);
    }

    public function testPhoneFormIsPresetFromTheCustomer()
    {
        $conversation = $this->receiveConversation();
        $customer = $conversation->customer;
        $customer->setPhones(['+31 20 555 0100', '+31 20 555 0199']);
        $customer->save();

        $data = $this->pageData($conversation);

        $this->assertSame([$customer->id => 'Casey Customer'], $data['name']);
        $this->assertSame('+31 20 555 0199', $data['phone']);
        $this->assertSame(['casey@customer.example.org'], $data['to_email']);
    }

    public function testADraftWithoutCustomerFindsItByItsRecipient()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action'     => 'save_draft',
            'mailbox_id' => $this->mailbox->id,
            'is_create'  => 1,
            'to'         => ['robin@customer.example.org'],
            'subject'    => 'Draft',
            'body'       => '<p>Draft</p>',
        ])->json();
        $conversation = Conversation::find($response['conversation_id']);
        $this->assertNull($conversation->customer_id);

        $data = $this->pageData($conversation);

        $this->assertEquals($robin->id, $data['customer']->id);
        $this->assertSame(['robin@customer.example.org'], array_keys($data['to']));
    }

    // Viewers.

    public function testViewersAreOthersReplyingFirst()
    {
        $conversation = $this->receiveConversation();
        $reader = $this->createUser(['first_name' => 'Reader']);
        $writer = $this->createUser(['first_name' => 'Writer']);
        \Cache::put('conv_view', [$conversation->id => [
            $reader->id      => ['r' => 0],
            $writer->id      => ['r' => 1],
            $this->agent->id => ['r' => 1],
        ]], 60);

        $data = $this->pageData($conversation);

        $this->assertSame([$writer->id, $reader->id], array_map(function ($viewer) {
            return $viewer['user']->id;
        }, $data['viewers']));
        $this->assertSame([1, 0], array_column($data['viewers'], 'replying'));
    }

    // From alias.

    public function testFromAliasIsTheAddressTheCustomerWroteTo()
    {
        $this->mailbox->aliases = 'sales@example.org, help@example.org (Help Desk)';
        $this->mailbox->aliases_reply = true;
        $this->mailbox->save();
        $conversation = $this->receiveConversation(['to' => 'help@example.org']);

        $data = $this->pageData($conversation);

        $this->assertSame([$this->mailbox->email, 'sales@example.org', 'help@example.org'], array_keys($data['from_aliases']));
        $this->assertSame('Help Desk', $data['from_aliases']['help@example.org']);
        $this->assertSame('help@example.org', $data['from_alias']);
    }

    public function testFromAliasIsTheOneLastRepliedFrom()
    {
        $this->mailbox->aliases = 'sales@example.org';
        $this->mailbox->aliases_reply = true;
        $this->mailbox->save();
        $conversation = $this->receiveConversation();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action'          => 'send_reply',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'body'            => '<p>Answer</p>',
            'from_alias'      => 'sales@example.org',
        ])->assertJson(['status' => 'success']);

        $this->assertSame('sales@example.org', $this->pageData($conversation)['from_alias']);
    }

    public function testFromAliasIsNotPresetWithoutAliasesOrWhenRepliedFromTheMailbox()
    {
        // Replying from aliases on, but no aliases: nothing to choose.
        $this->mailbox->aliases_reply = true;
        $this->mailbox->save();
        $conversation = $this->receiveConversation();
        $this->assertSame([], $this->pageData($conversation)['from_aliases']);

        $this->mailbox->aliases = 'sales@example.org';
        $this->mailbox->save();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Answer</p>',
        ])->assertJson(['status' => 'success']);

        $this->assertSame('', $this->pageData($conversation)['from_alias']);
    }

    public function testADraftKeepsItsFromAlias()
    {
        $this->mailbox->aliases = 'sales@example.org';
        $this->mailbox->aliases_reply = true;
        $this->mailbox->save();
        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'save_draft', 'mailbox_id' => $this->mailbox->id, 'is_create' => 1,
            'to'     => ['robin@customer.example.org'], 'subject' => 'Draft', 'body' => '<p>Draft</p>', 'from_alias' => 'sales@example.org',
        ])->json();

        $this->assertSame('sales@example.org', $this->pageData(Conversation::find($response['conversation_id']))['from_alias']);
    }

    // The conversation page.

    public function testOpenedInAFolderItIsNotInGoesToItsOwn()
    {
        $conversation = $this->receiveConversation();
        $deleted = $this->mailbox->folders()->where('type', Folder::TYPE_DELETED)->first();

        $response = $this->actingAs($this->agent)->get('/conversation/'.$conversation->id.'?folder_id='.$deleted->id.'&show_draft=5');

        $response->assertRedirect($conversation->url($conversation->folder_id, null, ['show_draft' => 5]));
    }

    public function testOpenedInAssignedWhenAssignedToMeGoesToMine()
    {
        $conversation = $this->receiveConversation();
        $conversation->user_id = $this->agent->id;
        $conversation->updateFolder();
        $conversation->save();
        $assigned = $this->mailbox->folders()->where('type', Folder::TYPE_ASSIGNED)->first();
        $mine = $this->mailbox->folders()->where('type', Folder::TYPE_MINE)->where('user_id', $this->agent->id)->first();

        $response = $this->actingAs($this->agent)->get('/conversation/'.$conversation->id.'?folder_id='.$assigned->id);

        $response->assertRedirect($conversation->url($mine->id));
    }

    public function testOpenedInAssignedWithoutAMineFolderGoesToItsFolder()
    {
        $conversation = $this->receiveConversation();
        $conversation->user_id = $this->agent->id;
        $conversation->updateFolder();
        $conversation->save();
        $assigned = $this->mailbox->folders()->where('type', Folder::TYPE_ASSIGNED)->first();
        $this->mailbox->folders()->where('type', Folder::TYPE_MINE)->where('user_id', $this->agent->id)->delete();

        $response = $this->actingAs($this->agent)->get('/conversation/'.$conversation->id.'?folder_id='.$assigned->id);

        $response->assertRedirect($conversation->url($conversation->folder_id));
    }

    public function testADraftOpensInTheNewConversationForm()
    {
        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'save_draft', 'mailbox_id' => $this->mailbox->id, 'is_create' => 1,
            'to'     => ['robin@customer.example.org'], 'subject' => 'Unfinished', 'body' => '<p>Draft</p>',
        ])->json();
        $conversation = Conversation::find($response['conversation_id']);

        $page = $this->actingAs($this->agent)->get($conversation->url());

        $page->assertOk()->assertViewIs('conversations.create')->assertSee('new-conversation', false);
    }

    public function testOpenedFromANotificationReadsJustThatOne()
    {
        $conversation = $this->receiveConversation();
        $other = $this->receiveConversation(['from' => 'robin@customer.example.org', 'subject' => 'Other']);
        foreach (['n-this', 'n-other'] as $id) {
            \DB::table('notifications')->insert([
                'id' => $id, 'type' => 'App\Notifications\WebsiteNotification', 'notifiable_type' => 'App\User',
                'notifiable_id' => $this->agent->id, 'data' => '{}', 'conversation_id' => $id == 'n-this' ? $conversation->id : $other->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->agent)->get($conversation->url().'&mark_as_read=n-other')->assertOk();

        $this->assertNotNull(\DB::table('notifications')->where('id', 'n-other')->value('read_at'));
        $this->assertNull(\DB::table('notifications')->where('id', 'n-this')->value('read_at'));
    }

    // New conversation page.

    public function testNewConversationPrefillsRecipientsAndBody()
    {
        $response = $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/new-ticket?subject=+Hello+&to=robin@customer.example.org,not-an-email,pat@customer.example.org&body='.urlencode('<p>Hi</p><script>alert(1)</script>'));

        $response->assertOk();
        $this->assertSame(['robin@customer.example.org' => 'robin@customer.example.org', 'pat@customer.example.org' => 'pat@customer.example.org'], $response->viewData('to'));
        $this->assertSame('Hello', $response->viewData('conversation')->subject);
        $this->assertStringContainsString('<p>Hi</p>', $response->viewData('thread')->body);
        $this->assertStringNotContainsString('<script', $response->viewData('thread')->body);
    }

    public function testNewConversationFromAForwardedThread()
    {
        $conversation = $this->receiveConversation([
            'subject' => 'Fwd: Broken lamp',
            'body'    => "See below.\n\nFrom: Robin Buyer <robin@customer.example.org>\nSubject: Broken lamp\n\nMy lamp is broken.",
        ]);
        $thread = $conversation->threads()->first();

        $response = $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/new-ticket?from_thread_id='.$thread->id);

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^Re:\s+Broken lamp$/', $response->viewData('conversation')->subject);
        $this->assertSame(['robin@customer.example.org'], $response->viewData('thread')->getToArray());
        $this->assertStringContainsString('My lamp is broken.', $response->viewData('thread')->body);
        $this->assertSame([], $response->viewData('attachments'));
    }

    /**
     * Its attachments are copied for the new conversation.
     */
    public function testNewConversationFromAThreadWithAttachments()
    {
        $conversation = $this->receiveConversation();
        $thread = $conversation->threads()->first();
        $this->storedAttachment($thread);

        $response = $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/new-ticket?from_thread_id='.$thread->id);

        $response->assertOk()->assertSee('notes.txt');
        $this->assertTrue((bool) $response->viewData('thread')->has_attachments);
        $copies = $response->viewData('attachments');
        $this->assertCount(1, $copies);
        $this->assertNull($copies[0]->thread_id, 'A copy, not yet on a thread.');
        $this->assertSame(2, Attachment::where('file_name', 'notes.txt')->count());
    }

    public function testNewConversationFromAThreadOfAnotherMailboxIsNotPrefilled()
    {
        $other_mailbox = $this->createMailbox();
        $this->receiveEmail($other_mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $other_mailbox->email, 'subject' => 'Secret']));
        $thread = Conversation::where('mailbox_id', $other_mailbox->id)->first()->threads()->first();

        $response = $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/new-ticket?from_thread_id='.$thread->id);

        $response->assertOk();
        $this->assertNull($response->viewData('thread'));
        $this->assertSame('', $response->viewData('conversation')->subject);
    }

    // Clone.

    public function testCloneCopiesAttachments()
    {
        $conversation = $this->receiveConversation();
        $thread = $conversation->threads()->first();
        $this->storedAttachment($thread);
        \Session::start();

        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/clone-ticket/'.$thread->id.'/'.csrf_token())->assertRedirect();

        $this->assertSame(2, Attachment::where('file_name', 'notes.txt')->count());
        $copy = Attachment::where('file_name', 'notes.txt')->where('thread_id', '!=', $thread->id)->first();
        $this->assertNotNull(Thread::find($copy->thread_id));
    }

    public function testCloneOfAMissingThreadGoesToTheMailbox()
    {
        \Session::start();

        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/clone-ticket/999999/'.csrf_token())
            ->assertRedirect($this->mailbox->url());
        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/clone-ticket/0/'.csrf_token())
            ->assertRedirect($this->mailbox->url());
        $this->assertSame(0, Conversation::count());
    }
}
