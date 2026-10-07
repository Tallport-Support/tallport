<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Events\UserCreatedConversation;
use App\Jobs\TriggerAction;
use App\Mailbox;
use App\Thread;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * App\Thread on its own: recipients, names and texts shown in the history,
 * metas, Thread::create() and Thread::createExtended() (used by the API).
 */
class ThreadModelTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Agent']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support Desk']);
        $this->customer = $this->createCustomer('casey@customer.example.org');
    }

    /**
     * A conversation without threads, as the API starts one.
     */
    protected function conversation(array $attributes = [])
    {
        $conversation = new Conversation();
        $conversation->type = Conversation::TYPE_EMAIL;
        $conversation->subject = 'Question';
        $conversation->mailbox_id = $this->mailbox->id;
        $conversation->customer_id = $this->customer->id;
        $conversation->customer_email = 'casey@customer.example.org';
        $conversation->status = Conversation::STATUS_ACTIVE;
        $conversation->state = Conversation::STATE_PUBLISHED;
        $conversation->source_via = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_EMAIL;
        foreach ($attributes as $name => $value) {
            $conversation->$name = $value;
        }
        $conversation->updateFolder();
        $conversation->save();

        return $conversation;
    }

    protected function thread(array $attributes = [], $conversation = null)
    {
        $conversation = $conversation ?: $this->conversation();
        $thread = Thread::create($conversation, $attributes['type'] ?? Thread::TYPE_MESSAGE, $attributes['body'] ?? '<p>Hello</p>', [], false);
        $thread->created_by_user_id = $this->agent->id;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        foreach ($attributes as $name => $value) {
            $thread->$name = $value;
        }
        $thread->save();

        return $thread;
    }

    public function testRecipientListsAsStringsWithoutExcludedAddresses()
    {
        $thread = new Thread();
        $thread->setTo(['casey@customer.example.org', 'pat@customer.example.org', 'casey@customer.example.org']);
        $thread->setCc('kim@customer.example.org, lee@customer.example.org');
        $thread->setBcc(['boss@example.org']);

        $this->assertSame(['casey@customer.example.org', 'pat@customer.example.org'], $thread->getTo());
        $this->assertSame('pat@customer.example.org', $thread->getToString(['casey@customer.example.org']));
        $this->assertSame('kim@customer.example.org, lee@customer.example.org', $thread->getCcString());
        $this->assertSame('lee@customer.example.org', $thread->getCcString(['kim@customer.example.org']));
        $this->assertSame('boss@example.org', $thread->getBccString());

        $thread->setBcc([]);
        $this->assertNull($thread->bcc);
        $this->assertSame('', $thread->getBccString());
    }

    public function testMainStatusFollowsTheConversationStatuses()
    {
        $thread = new Thread();
        $thread->status = Thread::STATUS_CLOSED;
        $this->assertSame(Conversation::STATUS_CLOSED, $thread->getMainStatus());

        $thread->status = Thread::STATUS_NOCHANGE;
        $this->assertSame(Conversation::toMainStatus(Thread::STATUS_NOCHANGE), $thread->getMainStatus());
    }

    public function testEmbedsAreSeparateFromAttachments()
    {
        $thread = $this->thread();
        Attachment::create('photo.png', 'image/png', Attachment::TYPE_IMAGE, 'png-bytes', null, true, $thread->id);
        Attachment::create('invoice.txt', 'text/plain', Attachment::TYPE_TEXT, 'invoice', null, false, $thread->id);

        $this->assertSame(['photo.png'], $thread->embeds()->pluck('file_name')->all());
        $this->assertSame(['invoice.txt'], $thread->attachments()->pluck('file_name')->all());
        $this->assertCount(2, $thread->all_attachments);
    }

    public function testCleanBodyOfAThreadWithoutBodyIsEmpty()
    {
        $thread = new Thread();
        $thread->body = null;

        $this->assertSame('', $thread->getCleanBody());
    }

    public function testLinksOpenInANewWindowExceptAnchorsAndLinksWithATarget()
    {
        $thread = new Thread();
        $thread->body = '<p><a href="https://example.org/a">A</a> <a href="https://example.org/b" target="_blank">B</a> <a href="#top">Top</a></p>';

        $body = $thread->getBodyWithFormatedLinks();

        $this->assertStringContainsString('<a target="_blank" href="https://example.org/a"', $body);
        $this->assertSame(1, substr_count($body, 'target="_blank" href="https://example.org/b"') + substr_count($body, 'href="https://example.org/b" target="_blank"'));
        $this->assertStringContainsString('<a href="#top">', $body);
    }

    public function testAssigneeNameForAnyoneYourselfAndADeletedUser()
    {
        $thread = new Thread();
        $this->assertSame('anyone', $thread->getAssigneeName());
        $this->assertSame('Anyone', $thread->getAssigneeName(true));

        $thread->user_id = $this->agent->id;
        $thread->created_by_user_id = $this->agent->id;
        $this->assertSame('yourself', $thread->getAssigneeName(false, $this->agent));
        $this->assertSame('Yourself', $thread->getAssigneeName(true, $this->agent));

        $thread->created_by_user_id = $this->createUser()->id;
        $this->assertSame('You', $thread->getAssigneeName(true, $this->agent));

        $thread->user_id = 999999;
        $this->assertSame('', $thread->getAssigneeName(false, $this->agent));
    }

    public function testCreatedByFallsBackToADeletedUserOrADummyCustomer()
    {
        $thread = new Thread();
        $thread->created_by_user_id = 999999;
        $this->assertSame('DELETED', $thread->getCreatedBy()->first_name);

        $thread = new Thread();
        $thread->created_by_customer_id = 999999;
        $created_by = $thread->getCreatedBy();
        $this->assertInstanceOf(\App\Customer::class, $created_by);
        $this->assertFalse($created_by->exists);
        $this->assertSame('Customer', $created_by->getFullName());

        $thread = new Thread();
        $thread->created_by_customer_id = $this->customer->id;
        $this->assertSame($this->customer->id, $thread->getCreatedBy()->id);
    }

    public function testPersonIsTheCustomerOrTheUser()
    {
        $thread = new Thread();
        $thread->type = Thread::TYPE_CUSTOMER;
        $thread->customer_id = $this->customer->id;
        $this->assertSame($this->customer->id, $thread->getPerson()->id);
        $this->assertSame($this->customer->id, $thread->getPerson(true)->id);

        $thread = new Thread();
        $thread->type = Thread::TYPE_NOTE;
        $thread->created_by_user_id = $this->agent->id;
        $this->assertSame($this->agent->id, $thread->getPerson()->id);
        $this->assertSame($this->agent->id, $thread->getPerson(true)->id);
    }

    public function testDraftsNameWhoEditedThem()
    {
        $editor = $this->createUser(['first_name' => 'Erin', 'last_name' => 'Editor']);
        $thread = $this->thread(['state' => Thread::STATE_DRAFT]);

        $this->assertSame('', $thread->getEditedByUserName());
        $this->assertSame('System created a draft', $thread->getActionText());

        $thread->edited_by_user_id = $editor->id;
        $thread->save();
        $thread = $thread->fresh();

        $this->assertSame('Erin Editor', $thread->getActionPerson());
        $this->assertSame('Erin Editor', $thread->getEditedByUserName());
        $this->assertSame("Erin Editor edited Alex's draft", $thread->getActionText('', false, false, null, $thread->getActionPerson()));

        $this->actingAs($editor);
        $this->assertSame('you', $thread->getActionPerson());
        $this->assertSame('you', $thread->getEditedByUserName());
    }

    public function testLineItemTexts()
    {
        $conversation = $this->conversation();
        $line_item = function ($action_type, array $meta = []) use ($conversation) {
            $thread = $this->thread(['type' => Thread::TYPE_LINEITEM, 'action_type' => $action_type, 'body' => ''], $conversation);
            if ($meta) {
                $thread->setMetas($meta);
            }

            return $thread;
        };

        $this->assertSame('Alex deleted', $line_item(Thread::ACTION_TYPE_DELETED_TICKET)->getActionText('', false, false, null, 'Alex'));
        $this->assertSame('Alex restored', $line_item(Thread::ACTION_TYPE_RESTORE_TICKET)->getActionText('', false, false, null, 'Alex'));
        $this->assertSame('Alex moved conversation from another mailbox', $line_item(Thread::ACTION_TYPE_MOVED_FROM_MAILBOX)->getActionText('', false, false, null, 'Alex'));

        $this->assertSame('Alex merged with another conversation', $line_item(Thread::ACTION_TYPE_MERGED, [Thread::META_MERGED_WITH_CONV => 5])
            ->getActionText('', false, false, null, 'Alex'));

        $other = $this->conversation(['subject' => 'Other']);
        $merged_into = $line_item(Thread::ACTION_TYPE_MERGED, [Thread::META_MERGED_INTO_CONV => $other->id]);
        $this->assertSame('Alex merged into conversation #<a href="'.$other->url().'" class="link-black">'.$other->number.'</a>',
        $merged_into->getActionText('', false, false, null, 'Alex'));
        $this->assertSame('Alex merged into conversation #'.$other->number, $merged_into->getActionText('', false, true, null, 'Alex'));

        $this->assertSame('Alex merged into conversation #', $line_item(Thread::ACTION_TYPE_MERGED, [Thread::META_MERGED_INTO_CONV => 999999])
            ->getActionText('', false, false, null, 'Alex'));
    }

    public function testCustomerChangedTexts()
    {
        $customer = $this->createCustomer('pat@customer.example.org', ['first_name' => 'Pat & Co', 'last_name' => 'Doe']);
        $thread = $this->thread(['type' => Thread::TYPE_LINEITEM, 'action_type' => Thread::ACTION_TYPE_CUSTOMER_CHANGED,
            'customer_id' => $customer->id, 'action_data' => 'casey@customer.example.org', 'body' => '']);

        $this->assertSame('Alex changed the customer to Pat & Co Doe in conversation #42', $thread->getActionText(42, false, false, null, 'Alex'));
        $this->assertSame('Alex changed the customer to <a href="'.$customer->url().'" title="casey@customer.example.org" class="link-black">Pat &amp; Co Doe</a>',
        $thread->getActionText('', true, false, null, 'Alex'));

        $thread->customer_id = 999999;
        $thread->unsetRelation('customer_cached');
        $this->assertSame('Alex changed the customer to <a href="" title="casey@customer.example.org" class="link-black"></a>',
        $thread->getActionText('', true, false, null, 'Alex'));
    }

    public function testForwardedConversationText()
    {
        $thread = $this->thread();
        $thread->setMeta('forward_parent_conversation_id', 7);
        $thread->setMeta(Thread::META_FORWARD_PARENT_CONVERSATION_NUMBER, 107);

        $this->assertTrue($thread->isForwarded());
        $this->assertSame('Alex forwarded a conversation #107', $thread->getActionText(108, false, false, null, 'Alex'));
    }

    public function testForwardedByAndForwardChildConversation()
    {
        $thread = $this->thread(['subtype' => Thread::SUBTYPE_FORWARD]);
        $child = $this->conversation(['subject' => 'Fwd: Question']);
        $thread->setMeta(Thread::META_FORWARD_CHILD_CONVERSATION_ID, $child->id);

        $this->assertTrue($thread->isForward());
        $this->assertSame($child->id, $thread->getForwardChildConversation()->id);
        $this->assertSame('Alex Agent', $thread->getForwardByFullName());
        $this->assertSame('you', $thread->getForwardByFullName($this->agent));

        $thread->created_by_user_id = 999999;
        $thread->unsetRelation('created_by_user');
        $this->assertSame('', $thread->getForwardByFullName());
    }

    public function testUnsetMeta()
    {
        $thread = new Thread();
        $thread->setMeta('a', 1);
        $thread->setMeta('b', 2);

        $thread->unsetMeta('a');
        $thread->unsetMeta('missing');

        $this->assertSame(['b' => 2], $thread->getMetas());
    }

    public function testFromNameForRepliesFollowsTheMailboxSetting()
    {
        $thread = $this->thread();

        $this->assertSame('Support Desk', $thread->getFromName());

        $this->mailbox->from_name = Mailbox::FROM_NAME_USER;
        $this->assertSame('Alex', $thread->getFromName($this->mailbox));

        $this->mailbox->from_name = Mailbox::FROM_NAME_CUSTOM;
        $this->mailbox->from_name_custom = '{%user.firstName%} at {%mailbox.name%}';
        $this->assertSame('Alex at Support Desk', $thread->getFromName($this->mailbox));
    }

    public function testBounceAndClearingSendStatusData()
    {
        $thread = new Thread();
        $this->assertFalse($thread->isBounce());

        $thread->updateSendStatusData(['is_bounce' => true, 'msg' => 'Mailbox unavailable']);
        $this->assertTrue($thread->isBounce());

        $thread->updateSendStatusData([]);
        $this->assertNull($thread->send_status_data);
        $this->assertFalse($thread->isBounce());
    }

    public function testCreateTakesTheGivenData()
    {
        $conversation = $this->conversation();

        $thread = Thread::create($conversation, Thread::TYPE_MESSAGE, '<p>Hi</p>', [
            'user_id'                => $this->agent->id,
            'message_id'             => 'abc@example.org',
            'headers'                => "Subject: Hi\n",
            'from'                   => 'support@example.org',
            'to'                     => ['casey@customer.example.org'],
            'cc'                     => ['kim@customer.example.org'],
            'bcc'                    => ['boss@example.org'],
            'source_via'             => Thread::PERSON_USER,
            'source_type'            => Thread::SOURCE_TYPE_WEB,
            'customer_id'            => $this->customer->id,
            'created_by_customer_id' => $this->customer->id,
            'created_by_user_id'     => $this->agent->id,
            'meta'                   => ['k' => 'v'],
        ]);
        $thread = $thread->fresh();

        $this->assertSame($this->agent->id, $thread->user_id);
        $this->assertSame('abc@example.org', $thread->message_id);
        $this->assertSame("Subject: Hi\n", $thread->headers);
        $this->assertSame('support@example.org', $thread->from);
        $this->assertSame(['casey@customer.example.org'], $thread->getToArray());
        $this->assertSame(['kim@customer.example.org'], $thread->getCcArray());
        $this->assertSame(['boss@example.org'], $thread->getBccArray());
        $this->assertSame($this->customer->id, $thread->created_by_customer_id);
        $this->assertSame(['k' => 'v'], $thread->getMetas());
        $this->assertSame(Conversation::STATUS_ACTIVE, $thread->status);
        $this->assertSame(Thread::STATE_PUBLISHED, $thread->state);
    }

    public function testCreateMarksTheFirstThread()
    {
        $this->knownBug('C18');

        $thread = Thread::create($this->conversation(), Thread::TYPE_MESSAGE, '<p>Hi</p>', [
            'from'  => 'support@example.org',
            'first' => true,
        ], false);

        $this->assertTrue((bool) $thread->first);
        $this->assertSame('support@example.org', $thread->from);
    }

    public function testCreateExtendedRefusesIncompleteData()
    {
        $conversation = $this->conversation();

        $this->assertFalse(Thread::createExtended(['type' => Thread::TYPE_NOTE], $conversation));
        $this->assertFalse(Thread::createExtended(['type' => Thread::TYPE_NOTE, 'body' => 'Note'], $conversation));

        $without_customer = $this->conversation(['customer_id' => null]);
        $this->assertFalse(Thread::createExtended(['type' => Thread::TYPE_CUSTOMER, 'body' => 'Hi'], $without_customer));

        $this->assertSame(0, Thread::count());
    }

    public function testCreateExtendedStartsAConversationByAUser()
    {
        \Queue::fake();
        \Event::fake([UserCreatedConversation::class]);
        $conversation = $this->conversation(['status' => Conversation::STATUS_ACTIVE]);

        $thread = Thread::createExtended([
            'type'               => Thread::TYPE_MESSAGE,
            'body'               => '<p>We shipped it.</p>',
            'created_by_user_id' => $this->agent->id,
            'bcc'                => ['boss@example.org'],
        ], $conversation);

        $thread = $thread->fresh();
        $conversation = $conversation->fresh();
        $this->assertTrue((bool) $thread->first);
        $this->assertSame(Thread::SOURCE_TYPE_API, $thread->source_type);
        $this->assertSame(['casey@customer.example.org'], $thread->getToArray());
        $this->assertSame(['boss@example.org'], $thread->getBccArray());

        $this->assertSame(Conversation::PERSON_USER, $conversation->source_via);
        $this->assertSame($this->agent->id, $conversation->created_by_user_id);
        $this->assertSame(['boss@example.org'], $conversation->getBccArray());
        $this->assertSame(Conversation::STATUS_PENDING, $conversation->status);

        \Event::assertDispatched(UserCreatedConversation::class);
        \Queue::assertPushed(TriggerAction::class, function ($job) {
            return $job->action == 'conversation.created_by_user';
        });
    }

    public function testCreateExtendedKeepsTheDateOfImportedThreads()
    {
        $this->knownBug('C20');

        $thread = Thread::createExtended([
            'type'        => Thread::TYPE_CUSTOMER,
            'body'        => '<p>Old message</p>',
            'imported'    => true,
            'created_at'  => '2026-01-02T03:04:05Z',
        ], $this->conversation(), $this->customer);

        $thread = $thread->fresh();
        $this->assertSame(1, (int) $thread->imported);
        $this->assertSame('2026-01-02 03:04:05', $thread->created_at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function testCreateExtendedStoresAttachments()
    {
        $conversation = $this->conversation();
        \Storage::disk('local')->put('upload/notes.txt', 'Some notes');
        $uploaded = new UploadedFile(\Storage::disk('local')->path('upload/notes.txt'), 'notes.txt', null, null, true);

        $thread = Thread::createExtended([
            'type'               => Thread::TYPE_NOTE,
            'body'               => '<p>See attached.</p>',
            'created_by_user_id' => $this->agent->id,
            'attachments'        => [
                $uploaded,
                ['file_name' => 'encoded.txt', 'data' => base64_encode('Encoded text')],
                ['file_name' => 'no-content.txt'],
                ['data' => base64_encode('No name')],
                ['file_name' => 'invalid.txt', 'data' => '!!!!'],
                ['file_name' => 'local.png', 'file_url' => 'http://127.0.0.1/local.png'],
            ],
        ], $conversation);

        $this->assertSame(['encoded.txt', 'notes.txt'], $thread->attachments()->orderBy('file_name')->pluck('file_name')->all());
        $this->assertTrue((bool) $thread->fresh()->has_attachments);
        $this->assertTrue((bool) $conversation->fresh()->has_attachments);
        $this->assertSame('text/plain', $thread->attachments()->where('file_name', 'encoded.txt')->value('mime_type'));
    }

    public function testCustomerReplyRestoresADeletedConversation()
    {
        $conversation = $this->conversation(['state' => Conversation::STATE_DELETED, 'status' => Conversation::STATUS_CLOSED]);
        $this->thread([], $conversation);
        $conversation->threads_count = 1;
        $conversation->save();

        Thread::createExtended(['type' => Thread::TYPE_CUSTOMER, 'body' => '<p>Any news?</p>'], $conversation, $this->customer);

        $conversation = $conversation->fresh();
        $this->assertSame(Conversation::STATE_PUBLISHED, $conversation->state);
        $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->status);
    }

    public function testFromIfDifferentFromReplyTo()
    {
        $other = $this->createCustomer('pat@customer.example.org');
        $headers = "From: Casey <casey@customer.example.org>\nReply-To: <help@customer.example.org>\n";
        $thread = $this->thread(['type' => Thread::TYPE_CUSTOMER, 'headers' => $headers, 'created_by_customer_id' => $other->id]);

        // The sender is not one of the thread customer's addresses.
        $this->assertSame('casey@customer.example.org', $thread->getFromIfDifferentFromReplyTo($this->customer));

        $thread->created_by_customer_id = $this->customer->id;
        $this->assertSame('', $thread->getFromIfDifferentFromReplyTo($this->customer));

        $thread->created_by_customer_id = 999999;
        $thread->unsetRelation('created_by_customer');
        $this->assertSame('', $thread->getFromIfDifferentFromReplyTo($this->customer));

        $thread->created_by_customer_id = null;
        $this->assertSame('', $thread->getFromIfDifferentFromReplyTo($this->customer));

        $thread->headers = "From: Casey <casey@customer.example.org>\n";
        $this->assertSame('', $thread->getFromIfDifferentFromReplyTo($this->customer));

        $thread->headers = "Reply-To: <help@customer.example.org>\n";
        $this->assertEmpty($thread->getFromHeader());
        $this->assertSame('', $thread->getFromIfDifferentFromReplyTo($this->customer));

        $thread->headers = '';
        $this->assertSame('', $thread->getFromHeader());
    }

    public function testMailDateIsNullWithoutADateHeader()
    {
        $thread = new Thread();
        $thread->headers = "Subject: Hi\n";
        $this->assertNull($thread->getMailDate());

        $thread->headers = "Subject: Hi\nDate: Fri, 02 Jan 2026 03:04:05 +0000\n";
        $this->assertSame('2026-01-02 03:04:05', $thread->getMailDate()->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function testBase64ImagesBecomeEmbeddedAttachments()
    {
        $png = base64_encode('png-bytes');
        $body = Thread::replaceBase64ImagesWithAttachments('<p><img src="data:image/png;base64,'.$png.'" alt="x"></p>');

        $attachment = Attachment::where('embedded', true)->first();
        $this->assertNotNull($attachment);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame('<p><img src="'.$attachment->url().'" alt="x"></p>', $body);

        // Data that doesn't decode is left as it is.
        $this->assertSame('<img src="data:image/png;base64,!!!!">', Thread::replaceBase64ImagesWithAttachments('<img src="data:image/png;base64,!!!!">'));
        $this->assertSame(1, Attachment::count());

        $this->assertSame('', Thread::replaceBase64ImagesWithAttachments(''));
        $this->assertNull(Thread::replaceBase64ImagesWithAttachments(null));
    }

    public function testMessageIds()
    {
        $thread = $this->thread(['type' => Thread::TYPE_CUSTOMER, 'message_id' => 'fs-abc123@example.org',
            'headers' => "Message-Id: <original@customer.example.org>\n"]);
        $this->assertSame('original@customer.example.org', $thread->getMessageId());

        $thread->message_id = 'real@customer.example.org';
        $this->assertSame('real@customer.example.org', $thread->getMessageId());

        $note = $this->thread(['type' => Thread::TYPE_NOTE]);
        $this->assertSame('', $note->getMessageId());
    }

    public function testThreadsCreatedAtTheSameTimeSortByIdNewestFirst()
    {
        $conversation = $this->conversation();
        $first = $this->thread(['created_at' => '2026-01-02 03:04:05'], $conversation);
        $second = $this->thread(['created_at' => '2026-01-02 03:04:05'], $conversation);
        $older = $this->thread(['created_at' => '2026-01-01 00:00:00'], $conversation);

        $sorted = Thread::sortThreads(collect([$first, $older, $second]))->pluck('id')->values()->all();

        $this->assertSame([$second->id, $first->id, $older->id], $sorted);
        $this->assertSame($second->id, Thread::getLastThread(collect([$older, $first, $second]))->id);
    }

    public function testActionTypeNameIncludesTypesAddedByModules()
    {
        $this->knownBug('C19');

        \Eventy::addFilter('thread.action_types', function ($action_types) {
            $action_types[150] = 'module-action';

            return $action_types;
        });
        $thread = new Thread();
        $thread->action_type = 150;

        $this->assertSame('module-action', $thread->getActionTypeName());
    }
}
