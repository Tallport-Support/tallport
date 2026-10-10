<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\ConversationFolder;
use App\Customer;
use App\Folder;
use App\Follower;
use App\Option;
use App\Thread;
use App\User;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * The Conversation model's own behaviour: numbering, reading its threads,
 * reply times, folders when assigning, moving and merging, forwarding,
 * creating conversations for channels, viewers, and the fallback search.
 */
class ConversationModelTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function tearDown(): void
    {
        $this->setCustomNumber(null);
        $this->setConsole(null);

        parent::tearDown();
    }

    protected function receive($subject = 'Question about my order', $mailbox = null, $from = 'Casey Customer <casey@customer.example.org>')
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from'    => $from,
            'to'      => $mailbox->email,
            'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * A thread created at the given time.
     */
    protected function addThread(Conversation $conversation, $type, $created_at, array $attributes = [])
    {
        $thread = Thread::create($conversation, $type, 'Body of '.$type, [
            'source_via'  => Thread::PERSON_USER,
            'source_type' => Thread::SOURCE_TYPE_WEB,
        ], false);
        foreach ($attributes as $name => $value) {
            $thread->$name = $value;
        }
        $thread->created_at = $created_at;
        $thread->save();

        return $thread;
    }

    protected function setCustomNumber($value)
    {
        (new \ReflectionProperty(Conversation::class, 'custom_number_cache'))->setValue(null, $value);
    }

    /**
     * Helper::isConsole() is cached; tests run in the console, the web doesn't.
     */
    protected function setConsole($value)
    {
        (new \ReflectionProperty(\App\Misc\Helper::class, 'is_console'))->setValue(null, $value);
    }

    protected function assertDateTime($expected, $actual)
    {
        $this->assertNotNull($actual);
        $this->assertSame($expected, Carbon::parse($actual)->format('Y-m-d H:i:s'));
    }

    protected function ids($collection)
    {
        return collect($collection)->pluck('id')->values()->all();
    }

    // Numbering.

    public function testTheNextTicketOptionSetsTheNextNumberOnce()
    {
        $this->receive('First');
        $current = (int) Conversation::max('number');

        Option::set('next_ticket', $current + 50);
        $second = $this->receive('Second');
        $this->assertEquals($current + 50, $second->getRawOriginal('number'));
        $this->assertNull(Option::where('name', 'next_ticket')->first());

        // A number already passed is ignored: numbering goes on from the highest.
        Option::set('next_ticket', 1);
        $third = $this->receive('Third');
        $this->assertEquals($current + 51, $third->getRawOriginal('number'));
        $this->assertNull(Option::where('name', 'next_ticket')->first());
    }

    public function testNumberIsTheIdUnlessCustomNumbersAreOn()
    {
        $conversation = $this->receive();
        \DB::table('conversations')->where('id', $conversation->id)->update(['number' => 5000]);
        $conversation = $conversation->fresh();

        $this->setCustomNumber(false);
        $this->assertEquals($conversation->id, $conversation->number);
        $this->assertSame('id', Conversation::numberFieldName());

        $this->setCustomNumber(true);
        $this->assertEquals(5000, $conversation->number);
        $this->assertSame('number', Conversation::numberFieldName());
    }

    // Threads.

    public function testThreadsAreReadNewestFirst()
    {
        $conversation = $this->receive();
        $customer = $conversation->threads()->first();
        $customer->created_at = '2026-10-01 10:00:00';
        $customer->save();
        $note = $this->addThread($conversation, Thread::TYPE_NOTE, '2026-10-01 10:05:00');
        $reply = $this->addThread($conversation, Thread::TYPE_MESSAGE, '2026-10-01 10:07:00');
        $lineitem = $this->addThread($conversation, Thread::TYPE_LINEITEM, '2026-10-01 10:08:00');
        $draft = $this->addThread($conversation, Thread::TYPE_MESSAGE, '2026-10-01 10:09:00', ['state' => Thread::STATE_DRAFT]);

        $this->assertSame([$reply->id, $customer->id], $this->ids($conversation->getReplies()));
        $this->assertSame([$reply->id, $note->id], $this->ids($conversation->getThreads(1, 2)));
        $this->assertSame([$note->id], $this->ids($conversation->getThreads(null, null, [Thread::TYPE_NOTE])));
        $this->assertSame(
            [$draft->id, $lineitem->id, $reply->id, $note->id, $customer->id],
            $this->ids($conversation->getThreads(null, null, [], [Thread::STATE_DRAFT, Thread::STATE_PUBLISHED]))
        );
        $this->assertSame($customer->id, $conversation->getFirstThread()->id);
        $this->assertSame($note->id, $conversation->getLastThread([Thread::TYPE_NOTE])->id);
        $this->assertSame($reply->id, $conversation->getLastThread([Thread::TYPE_MESSAGE, Thread::TYPE_CUSTOMER])->id);
    }

    public function testNotesCountAsRepliesOnPhoneConversations()
    {
        $conversation = $this->receive();
        $conversation->threads()->update(['created_at' => now()->subMinutes(10)]);
        $reply = $this->addThread($conversation, Thread::TYPE_MESSAGE, now()->subMinutes(5));
        $note = $this->addThread($conversation, Thread::TYPE_NOTE, now()->subMinute());

        $this->assertSame($reply->id, $conversation->getLastReply(true)->id);

        $conversation->type = Conversation::TYPE_PHONE;
        $this->assertSame($note->id, $conversation->getLastReply(true)->id);
        $this->assertSame($reply->id, $conversation->getLastReply()->id);
    }

    // Reply times.

    public function testLastCustomerReplyAt()
    {
        $conversation = new Conversation();
        $conversation->last_reply_at = '2026-10-01 09:00:00';
        $conversation->last_reply_from = Conversation::PERSON_USER;
        $this->assertNull($conversation->getLastCustomerReplyAt());

        $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
        $this->assertDateTime('2026-10-01 09:00:00', $conversation->getLastCustomerReplyAt());

        $conversation->last_customer_reply_at = '2026-10-01 08:00:00';
        $this->assertDateTime('2026-10-01 08:00:00', $conversation->getLastCustomerReplyAt());
    }

    public function testWaitingSinceCanBeTheFirstUnansweredCustomerMessage()
    {
        config(['app.waiting_since_as_first_unanswered_customer_message' => true]);

        $conversation = new Conversation();
        $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
        $conversation->last_reply_at = '2026-10-01 09:00:00';

        // The customer writes again: still waiting since the first message.
        $conversation->setLastReplyAt('2026-10-01 10:00:00', Conversation::PERSON_CUSTOMER);
        $this->assertDateTime('2026-10-01 09:00:00', $conversation->last_reply_at);
        $this->assertDateTime('2026-10-01 10:00:00', $conversation->last_customer_reply_at);

        // After an agent's reply the next customer message starts the wait.
        $conversation->last_reply_from = Conversation::PERSON_USER;
        $conversation->setLastReplyAt('2026-10-01 11:00:00', Conversation::PERSON_CUSTOMER);
        $this->assertDateTime('2026-10-01 11:00:00', $conversation->last_reply_at);
        $this->assertDateTime('2026-10-01 11:00:00', $conversation->last_customer_reply_at);
    }

    public function testSetLastReplyAtAcceptsCarbon()
    {

        $conversation = new Conversation();
        $conversation->setLastReplyAt(Carbon::parse('2026-10-01 10:00:00'), Conversation::PERSON_CUSTOMER);

        $this->assertDateTime('2026-10-01 10:00:00', $conversation->last_reply_at);
    }

    // Names.

    public function testNamesForStatesAndSubjects()
    {
        $this->assertSame('Draft', Conversation::stateCodeToName(Conversation::STATE_DRAFT));
        $this->assertSame('', Conversation::stateCodeToName(99));

        $conversation = new Conversation();
        $this->assertSame('(no subject)', $conversation->getSubject());
        $conversation->subject = 'Order';
        $this->assertSame('Order', $conversation->getSubject());
    }

    public function testAssigneeName()
    {
        $other = $this->createUser(['first_name' => 'Olivia', 'last_name' => 'Other']);
        $conversation = $this->receive();
        $this->actingAs($this->agent);

        $this->assertSame('anyone', $conversation->getAssigneeName());
        $this->assertSame('Anyone', $conversation->getAssigneeName(true));

        $conversation->user_id = $this->agent->id;
        $this->assertSame('me', $conversation->getAssigneeName());
        $this->assertSame('Me', $conversation->getAssigneeName(true));

        $conversation->user_id = $other->id;
        $this->assertSame('Olivia Other', $conversation->getAssigneeName());

        // An assignee who is gone.
        $conversation->user_id = $other->id + 1000;
        $conversation->unsetRelation('user');
        $this->assertSame('', $conversation->getAssigneeName());
    }

    // Assigning and folders.

    public function testAssigningUnfollowsTheNewAssignee()
    {
        $conversation = $this->receive();
        $follower = new Follower();
        $follower->conversation_id = $conversation->id;
        $follower->user_id = $this->agent->id;
        $follower->save();

        $conversation->setUser($this->agent->id);
        $conversation->save();

        $this->assertFalse(Follower::where('conversation_id', $conversation->id)->exists());
        $this->assertEquals(Folder::TYPE_ASSIGNED, Folder::find($conversation->folder_id)->type);

        $conversation->setUser(Conversation::USER_UNASSIGNED);
        $this->assertNull($conversation->user_id);
        $this->assertEquals(Folder::TYPE_UNASSIGNED, Folder::find($conversation->folder_id)->type);
    }

    public function testUpdateFolderFollowsAChangedMailbox()
    {
        $other_mailbox = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $conversation = $this->receive();
        $conversation->mailbox;

        $conversation->mailbox_id = $other_mailbox->id;
        $conversation->updateFolder();

        $folder = Folder::find($conversation->folder_id);
        $this->assertEquals($other_mailbox->id, $folder->mailbox_id);
        $this->assertEquals(Folder::TYPE_UNASSIGNED, $folder->type);
    }

    public function testFolderOfAConversation()
    {
        $other = $this->createUser();
        $this->mailbox->users()->attach($other->id);
        $this->mailbox->syncPersonalFolders([$this->agent->id, $other->id]);
        $conversation = $this->receive();
        $conversation->setUser($this->agent->id);
        $conversation->save();
        $this->actingAs($this->agent);

        $mine = $this->mailbox->folders()->where('type', Folder::TYPE_MINE)->where('user_id', $this->agent->id)->first();
        $others_mine = $this->mailbox->folders()->where('type', Folder::TYPE_MINE)->where('user_id', $other->id)->first();
        $starred = $this->mailbox->folders()->where('type', Folder::TYPE_STARRED)->where('user_id', $this->agent->id)->first();

        $this->assertTrue($conversation->isInFolderAllowed($mine));
        $this->assertFalse($conversation->isInFolderAllowed($others_mine));
        $this->assertFalse($conversation->isInFolderAllowed($starred));

        // Shown in Mine when it's the user's, else its own folder.
        $this->assertEquals($mine->id, $conversation->getCurrentFolder(123));
        $conversation->user_id = null;
        $this->assertEquals($conversation->folder_id, $conversation->getCurrentFolder(123));
        $conversation->folder_id = null;
        $conversation->unsetRelation('folder');
        $this->assertSame(123, $conversation->getCurrentFolder(123));
    }

    public function testStarredCacheIsClearedForTheCurrentUser()
    {
        $conversation = $this->receive();
        $this->assertFalse(Conversation::clearStarredByUserCache(null, $this->mailbox->id));

        $this->actingAs($this->agent);
        $this->assertFalse($conversation->isStarredByUser());
        $conversation->addToFolder(Folder::TYPE_STARRED, $this->agent->id);

        // Cached until cleared.
        $this->assertFalse($conversation->isStarredByUser());
        Conversation::clearStarredByUserCache(null, $this->mailbox->id);
        $this->assertTrue($conversation->isStarredByUser());
    }

    public function testUsersWhoSeeOnlyAssignedConversationsGetOnlyTheirsInAFolder()
    {
        $restricted = $this->createUser(['permissions' => [User::PERM_ONLY_ASSIGNED_TICKETS => true]]);
        $this->mailbox->users()->attach($restricted->id);
        $this->mailbox->syncPersonalFolders([$restricted->id]);

        $theirs = $this->receive('Theirs');
        $theirs->changeUser($restricted->id, $this->agent);
        $theirs->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        $others = $this->receive('Others');
        $others->changeUser($this->agent->id, $this->agent);
        $others->changeStatus(Conversation::STATUS_CLOSED, $this->agent);

        $theirs_draft = $this->receive('Their draft');
        $theirs_draft->created_by_user_id = $restricted->id;
        $theirs_draft->save();
        $theirs_draft->addToFolder(Folder::TYPE_DRAFTS);
        $others_draft = $this->receive('Other draft');
        $others_draft->created_by_user_id = $this->agent->id;
        $others_draft->save();
        $others_draft->addToFolder(Folder::TYPE_DRAFTS);

        $closed = $this->mailbox->folders()->where('type', Folder::TYPE_CLOSED)->first();
        $drafts = $this->mailbox->folders()->where('type', Folder::TYPE_DRAFTS)->first();

        // In the console (fetching, commands) the restriction doesn't apply.
        $this->actingAs($restricted->fresh());
        $this->assertCount(2, Conversation::getQueryByFolder($closed, $restricted->id)->get());

        $this->setConsole(false);
        $this->assertSame([$theirs->id], $this->ids(Conversation::getQueryByFolder($closed, $restricted->id)->get()));
        $this->assertSame([$theirs_draft->id], $this->ids(Conversation::getQueryByFolder($drafts, $restricted->id)->get()));
    }

    public function testFolderChangesWithNothingToDo()
    {
        $conversation = $this->receive();
        $this->assertFalse($conversation->isStarredByUser());

        // No such folder: a user without personal folders here.
        $outsider = $this->createUser();
        $this->assertFalse($conversation->addToFolder(Folder::TYPE_STARRED, $outsider->id));
        $this->assertFalse($conversation->removeFromFolder(Folder::TYPE_STARRED, $outsider->id));

        // Drafts stay while there is a draft.
        $conversation->addToFolder(Folder::TYPE_DRAFTS);
        $this->addThread($conversation, Thread::TYPE_MESSAGE, now(), ['state' => Thread::STATE_DRAFT]);
        $this->assertFalse($conversation->maybeRemoveFromDrafts());
        $drafts = $this->mailbox->folders()->where('type', Folder::TYPE_DRAFTS)->first();
        $this->assertTrue(ConversationFolder::where('conversation_id', $conversation->id)->where('folder_id', $drafts->id)->exists());

        // A status that doesn't exist changes nothing.
        $conversation->changeStatus(99, $this->agent);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
    }

    public function testNoNextConversationInTheSentFolderOfAllMailboxes()
    {
        $conversation = $this->receive('First');
        $this->receive('Second');
        $this->actingAs($this->agent);

        $this->assertSame(
            route('mailboxes.view.folder', ['id' => $this->mailbox->id, 'folder_id' => $conversation->folder_id]),
            $conversation->urlNext(-\App\Misc\AllMailboxes::TYPE_SENT)
        );
    }

    // Moving and merging.

    public function testMovingKeepsStarsAndDraftsInTheNewMailbox()
    {
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $conversation = $this->receive();
        $conversation->star($this->agent);
        $conversation->addToFolder(Folder::TYPE_DRAFTS);

        $conversation->moveToMailbox($sales, $this->agent);

        $folders = ConversationFolder::where('conversation_id', $conversation->id)->get()->map(function ($row) {
            return Folder::find($row->folder_id);
        });
        $this->assertCount(2, $folders);
        foreach ($folders as $folder) {
            $this->assertEquals($sales->id, $folder->mailbox_id);
        }
        $this->assertEqualsCanonicalizing([Folder::TYPE_STARRED, Folder::TYPE_DRAFTS], $folders->pluck('type')->all());
        $this->assertSame([$conversation->id], Conversation::getUserStarredConversationIds($sales->id, $this->agent->id));
        $this->assertEquals($this->mailbox->id, $conversation->fresh()->getMeta('orig_mailbox_id'));
    }

    public function testMergingMovesTheStarAndAttachmentsFlag()
    {
        $first = $this->receive('First');
        $second = $this->receive('Second');
        $second->has_attachments = true;
        $second->save();
        $second->star($this->agent);

        $this->assertFalse($first->mergeConversations($first, $this->agent));
        $this->assertTrue($first->mergeConversations($second, $this->agent));

        $this->assertTrue((bool) $first->fresh()->has_attachments);
        $starred = Conversation::getUserStarredConversationIds($this->mailbox->id, $this->agent->id);
        $this->assertSame([$first->id], $starred);
        $this->assertEquals(Conversation::STATE_DELETED, $second->fresh()->state);
    }

    public function testMergingAConversationFromAnotherMailboxUpdatesItsCounters()
    {
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $first = $this->receive('First');
        $second = $this->receive('Second', $sales);
        $unassigned = $sales->folders()->where('type', Folder::TYPE_UNASSIGNED)->first();
        $this->assertEquals(1, $unassigned->fresh()->active_count);
        $second_thread = $second->threads()->first();

        $this->assertTrue($first->mergeConversations($second, $this->agent));

        $this->assertEquals(0, $unassigned->fresh()->active_count);
        $this->assertEquals($first->id, $second_thread->fresh()->conversation_id);
    }

    // Forwarding.

    public function testForwardingCreatesAConversationWithCopiesOfTheAttachments()
    {
        $conversation = $this->receive();
        $customer_thread = $conversation->threads()->first();
        $attachment = Attachment::create('invoice.pdf', 'application/pdf', null, 'PDF content', null, false, $customer_thread->id);
        $customer_thread->has_attachments = true;
        $customer_thread->save();

        $hooked = [];
        \Eventy::addAction('conversation.user_forwarded', function ($conversation, $thread, $forwarded_conversation, $forwarded_thread) use (&$hooked) {
            $hooked[] = $forwarded_conversation->id;
        }, 20, 4);

        $conversation->forward($this->agent, 'Please have a look', 'partner@example.org', [
            'cc' => ['colleague@example.org'],
        ], true);

        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();
        $this->assertEquals(Thread::SUBTYPE_FORWARD, $note->subtype);
        $forwarded = Conversation::find($note->getMeta(Thread::META_FORWARD_CHILD_CONVERSATION_ID));
        $this->assertSame([$forwarded->id], $hooked);
        $this->assertSame('Fwd: '.$conversation->subject, $forwarded->subject);
        $this->assertSame('partner@example.org', $forwarded->customer_email);
        $this->assertSame('partner@example.org', $forwarded->customer->getMainEmail());
        $this->assertEqualsCanonicalizing(['colleague@example.org', 'partner@example.org'], $forwarded->getCcArray());
        $this->assertTrue((bool) $forwarded->has_attachments);

        $forwarded_thread = $forwarded->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertSame('Please have a look', $forwarded_thread->body);
        $this->assertEquals($conversation->id, $forwarded_thread->getMeta(Thread::META_FORWARD_PARENT_CONVERSATION_ID));
        $this->assertEquals($note->id, $forwarded_thread->getMeta(Thread::META_FORWARD_PARENT_THREAD_ID));
        $this->assertTrue((bool) $forwarded_thread->has_attachments);
        $copy = $forwarded_thread->attachments()->first();
        $this->assertNotEquals($attachment->id, $copy->id);
        $this->assertSame('PDF content', $copy->getFileContents());
        $this->assertNotSame($attachment->getStorageFilePath(), $copy->getStorageFilePath());

        // The forwarded email goes out to the new recipient.
        $this->assertCount(1, $this->sentEmailsTo('partner@example.org'));
    }

    public function testForwardingCopiesAttachmentsFromRemoteStorage()
    {

        config(['filesystems.default' => 'remote_test']);
        \Storage::fake('remote_test');
        $conversation = $this->receive();
        $customer_thread = $conversation->threads()->first();
        $attachment = Attachment::create('invoice.pdf', 'application/pdf', null, 'PDF content', null, false, $customer_thread->id);

        $conversation->forward($this->agent, 'Please have a look', 'partner@example.org', [], true);

        // A copy of its own, which stays when the original goes.
        $forwarded = Conversation::where('customer_email', 'partner@example.org')->first();
        $this->assertTrue((bool) $forwarded->has_attachments);
        $copy = $forwarded->threads()->first()->attachments()->first();
        $this->assertNotSame($attachment->getStorageFilePath(), $copy->getStorageFilePath());
        Attachment::deleteForever(collect([$attachment]));
        $this->assertSame('PDF content', $copy->getFileContents());
    }

    // Creating conversations for channels.

    public function testCreateAssignsAndMarksImportedThreads()
    {
        $customer = $this->createCustomer();
        $result = Conversation::create([
            'type'        => Conversation::TYPE_PHONE,
            'subject'     => 'Call',
            'mailbox_id'  => $this->mailbox->id,
            'source_type' => Conversation::SOURCE_TYPE_WEB,
            'user_id'     => $this->agent->id,
            'imported'    => true,
        ], [
            ['type' => Thread::TYPE_MESSAGE, 'body' => 'We called the customer', 'created_by_user_id' => $this->agent->id],
        ], $customer);

        $conversation = $result['conversation']->fresh();
        $this->assertEquals(Conversation::PERSON_USER, $conversation->source_via);
        $this->assertEquals(Conversation::STATUS_PENDING, $conversation->status);
        $this->assertEquals($this->agent->id, $conversation->user_id);
        $this->assertTrue((bool) $conversation->imported);
        $this->assertTrue((bool) $result['thread']->imported);
    }

    public function testCreateWithoutThreadsLeavesNoConversation()
    {
        $customer = $this->createCustomer();
        $before = Conversation::count();

        $this->assertFalse(Conversation::create([
            'type'        => Conversation::TYPE_EMAIL,
            'subject'     => 'Empty',
            'mailbox_id'  => $this->mailbox->id,
            'source_type' => Conversation::SOURCE_TYPE_API,
            'user_id'     => 999999,
        ], [['type' => Thread::TYPE_CUSTOMER, 'body' => '']], $customer));

        $this->assertSame($before, Conversation::count());
    }

    // Addresses.

    public function testSanitizeEmailsCreatesCustomersForNamedAddresses()
    {
        $emails = Conversation::sanitizeEmails(['Jane Doe <jane@example.org>', 'Prince <prince@example.org>', 'plain@example.org']);

        $this->assertSame(['jane@example.org', 'prince@example.org', 'plain@example.org'], array_values($emails));
        $jane = Customer::getByEmail('jane@example.org');
        $this->assertSame(['Jane', 'Doe'], [$jane->first_name, $jane->last_name]);
        $this->assertSame('Prince', Customer::getByEmail('prince@example.org')->first_name);
    }

    public function testExcludedAddressesIncludeEveryCustomerAddress()
    {
        $conversation = $this->receive();
        $conversation->customer_email = 'one@example.org,two@example.org';

        $excluded = $conversation->getExcludeArray();
        $this->assertContains($this->mailbox->email, $excluded);
        $this->assertContains('one@example.org', $excluded);
        $this->assertContains('two@example.org', $excluded);
    }

    public function testChangeCustomer()
    {
        $conversation = $this->receive();
        $this->assertFalse($conversation->changeCustomer('nobody@example.org'));

        $customer = $this->createCustomer('new@example.org');
        $this->assertTrue($conversation->changeCustomer('', $customer, $this->agent));
        $this->assertSame('new@example.org', $conversation->fresh()->customer_email);
        $this->assertEquals($customer->id, $conversation->fresh()->customer_id);
    }

    public function testSignatureVariablesAreReplaced()
    {
        $this->mailbox->signature = 'Regards, {%user.firstName%} from {%mailbox.name%}';
        $this->mailbox->save();
        $conversation = $this->receive();

        $this->assertSame('Regards, Agent from Support', $conversation->getSignatureProcessed(['user' => $this->agent]));
        $this->assertSame('No variables', $conversation->replaceTextVars('No variables'));
    }

    // Meta and mailbox.

    public function testMeta()
    {
        $conversation = $this->receive();
        $this->assertSame('default', $conversation->getMeta('missing', 'default'));

        $conversation->setMeta('key', 'value', true);
        $this->assertSame('value', $conversation->fresh()->getMeta('key'));
    }

    public function testChatStartsANewConversationAfterClosingWhenTheMailboxSaysSo()
    {
        $conversation = $this->receive();
        $conversation->status = Conversation::STATUS_CLOSED;
        $this->assertFalse($conversation->chatShouldStartNew());

        $this->mailbox->meta = ['chat_start_new' => true];
        $this->mailbox->save();
        $conversation->load('mailbox');
        $this->assertTrue($conversation->chatShouldStartNew());

        $conversation->status = Conversation::STATUS_ACTIVE;
        $this->assertFalse($conversation->chatShouldStartNew());
    }

    public function testMailboxAccessAndArchivedAreRemembered()
    {
        $outsider = $this->createUser();
        $conversation = $this->receive();
        $this->setConsole(false);

        $this->assertTrue($conversation->userHasAccessToMailbox($this->agent->id));
        $this->assertFalse($conversation->userHasAccessToMailbox($outsider->id));
        $this->assertFalse($conversation->isMailboxArchived());

        // Remembered for the request.
        $this->mailbox->users()->attach($outsider->id);
        $this->assertFalse($conversation->userHasAccessToMailbox($outsider->id));
        $this->assertFalse($conversation->isMailboxArchived());
    }

    // Viewers.

    public function testViewersReplyingUsersFirst()
    {
        $replying = $this->createUser(['first_name' => 'Rita']);
        $viewing = $this->createUser(['first_name' => 'Vic']);
        $stale = $this->createUser(['first_name' => 'Stan']);
        $first = $this->receive('First');
        $second = $this->receive('Second');
        $third = $this->receive('Third');
        $now = now()->toDateTimeString();

        \Cache::put('conv_view', [
            $first->id => [
                $viewing->id    => ['t' => $now],
                $replying->id   => ['t' => $now, 'r' => 1],
                $stale->id      => ['t' => now()->subMinutes(5)->toDateTimeString()],
                $this->agent->id => ['t' => $now],
            ],
            // Only users who are gone.
            $second->id => [999999 => ['t' => $now]],
        ]);

        $viewers = Conversation::getViewersInfo(collect([$first, $second, $third]), ['id', 'first_name', 'last_name'], [$this->agent->id]);

        $this->assertSame([$first->id], array_keys($viewers));
        $this->assertEquals($replying->id, $viewers[$first->id]['user_id']);
        $this->assertSame('Rita', $viewers[$first->id]['user']->first_name);
        $this->assertTrue($viewers[$first->id]['replying']);
        $this->assertSame([$replying->id, $viewing->id], array_map(function ($item) {
            return $item['user']->id;
        }, $viewers[$first->id]['users']));
        $this->assertFalse($viewers[$first->id]['users'][1]['replying']);
    }

    // Bulk actions.

    public function testBulkActionsSkipWhatTheUserMayNotDo()
    {
        $outsider = $this->createUser();
        $no_access = $this->createUser();
        $conversation = $this->receive();

        Conversation::bulkChangeUser([$conversation->id], $this->agent->id, $outsider);
        $this->assertNull($conversation->fresh()->user_id);

        Conversation::bulkChangeUser([$conversation->id], $no_access->id, $this->agent);
        $this->assertNull($conversation->fresh()->user_id);

        Conversation::bulkDelete([$conversation->id], $outsider);
        $this->assertEquals(Conversation::STATE_PUBLISHED, $conversation->fresh()->state);
    }

    // The search without the full-text index.

    public function testSearchFilters()
    {
        $other = $this->createUser();
        $this->mailbox->users()->attach($other->id);
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $hidden = $this->createMailbox([], ['name' => 'Hidden']);

        $order = $this->receive('Order status', null, 'Olga Orders <olga@example.org>');
        $order->changeUser($this->agent->id, $this->agent);
        $order->has_attachments = true;
        $order->save();
        $refund = $this->receive('Refund please', null, 'Rob Refunds <rob@example.org>');
        $refund->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        $sales_one = $this->receive('Sales question', $sales);
        $sales_one->type = Conversation::TYPE_PHONE;
        $sales_one->save();
        $this->receive('Hidden question', $hidden);
        \DB::table('conversations')->where('id', $refund->id)->update(['created_at' => '2026-01-15 12:00:00']);
        $follower = new Follower();
        $follower->conversation_id = $refund->id;
        $follower->user_id = $this->agent->id;
        $follower->save();

        $this->actingAs($this->agent);
        $search = function ($q, array $filters = []) {
            return $this->ids(Conversation::search($q, $filters, $this->agent)->get()->sortBy('id'));
        };

        $this->assertSame([$order->id, $refund->id, $sales_one->id], $search(''));
        $this->assertSame([$order->id], $search('olga'));
        $this->assertSame([$refund->id], $search('Rob Refunds'));
        $this->assertSame([$refund->id], $search('', ['mailbox' => $this->mailbox->id, 'status' => [Conversation::STATUS_CLOSED]]));
        $this->assertSame([$order->id, $refund->id], $search('', ['mailbox' => $this->mailbox->id, 'status' => [Conversation::STATUS_ACTIVE, Conversation::STATUS_CLOSED]]));
        // A mailbox the user can't see is ignored.
        $this->assertSame([$order->id, $refund->id, $sales_one->id], $search('', ['mailbox' => $hidden->id]));
        $this->assertSame([$order->id], $search('', ['assigned' => $this->agent->id]));
        $this->assertSame([$refund->id, $sales_one->id], $search('', ['assigned' => Conversation::USER_UNASSIGNED]));
        $this->assertSame([$refund->id], $search('', ['customer' => $refund->customer_id]));
        $this->assertSame([$order->id, $refund->id, $sales_one->id], $search('', ['state' => [Conversation::STATE_PUBLISHED]]));
        $this->assertSame([$order->id, $refund->id, $sales_one->id], $search('', ['state' => [Conversation::STATE_PUBLISHED, Conversation::STATE_DELETED]]));
        $this->assertSame([$refund->id], $search('', ['subject' => 'REFUND']));
        $this->assertSame([$order->id], $search('', ['attachments' => 'yes']));
        $this->assertSame([$refund->id, $sales_one->id], $search('', ['attachments' => 'no']));
        $this->assertSame([$sales_one->id], $search('', ['type' => Conversation::TYPE_PHONE]));
        $this->assertSame([$order->id, $refund->id, $sales_one->id], $search('', ['body' => 'where is my order']));
        $this->assertSame([$refund->id], $search('', ['number' => $refund->number]));
        $this->assertSame([$refund->id], $search('', ['following' => 'yes']));
        $this->assertSame([$sales_one->id], $search('', ['id' => $sales_one->id]));
        $this->assertSame([$refund->id], $search('', ['before' => '2026-01-15']));
        $this->assertSame([$order->id, $sales_one->id], $search('', ['after' => '2026-01-16']));
    }
}
