<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Http\Controllers\ConversationsController;
use App\MailboxUser;
use App\Misc\ConversationReplies;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Tests\FeatureTestCase;

/**
 * Sending and saving drafts (the send_reply, save_draft and discard_draft
 * actions behind the composers) on their less common paths: stale or foreign
 * drafts, size limits, attachments, forwarding and sending to several
 * people, custom and phone conversations, undoing, and finding the customer
 * of a phone conversation.
 */
class ReplyAndDraftEdgeCasesTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveConversation(array $options = [], $mailbox = null)
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $this->receiveEmail($mailbox, $this->makeEmail(array_merge([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $mailbox->email,
            'subject' => 'Question about my order',
        ], $options)));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function ajax($action, array $data, $user = null)
    {
        $response = $this->postAjax($user ?: $this->agent, '/conversation/ajax', array_merge([
            'action'     => $action,
            'mailbox_id' => $this->mailbox->id,
        ], $data));

        return $response->json();
    }

    protected function assertSuccess(array $response)
    {
        $this->assertSame('success', $response['status'], json_encode($response));
    }

    protected function assertError($message, array $response)
    {
        $this->assertSame('error', $response['status']);
        $this->assertSame($message, trim($response['msg']));
    }

    /**
     * An uploaded file, as the editors send it: its encrypted ID.
     */
    protected function upload($name = 'notes.txt', $contents = 'Some notes', $embedded = false)
    {
        $attachment = Attachment::create($name, 'text/plain', null, $contents, null, $embedded, null, $this->agent->id);

        return [$attachment, encrypt($attachment->id)];
    }

    protected function replyDraft(Conversation $conversation, $body = '<p>Not sent yet</p>')
    {
        $response = $this->ajax('save_draft', ['conversation_id' => $conversation->id, 'body' => $body]);
        $this->assertSuccess($response);

        return Thread::find($response['thread_id']);
    }

    protected function phoneCustomer(array $data, $user = null)
    {
        return app(ConversationsController::class)->processPhoneCustomer(new Request($data), $user ?: $this->agent);
    }

    // Sending: problems.

    public function testADraftOfAnotherConversationIsRefused()
    {
        $conversation = $this->receiveConversation();
        $other = $this->receiveConversation(['from' => 'robin@customer.example.org']);
        $draft = $this->replyDraft($other);

        $this->assertError('Incorrect thread', $this->ajax('send_reply', ['conversation_id' => $conversation->id, 'thread_id' => $draft->id, 'body' => '<p>Hi</p>']));
        $this->assertError('Incorrect thread', $this->ajax('save_draft', ['conversation_id' => $conversation->id, 'thread_id' => $draft->id, 'body' => '<p>Hi</p>']));
        $this->assertEquals(Thread::STATE_DRAFT, $draft->fresh()->state);
    }

    public function testSavingADraftUsesOnlyItsOwnFields()
    {
        $conversation = $this->receiveConversation();
        $other = $this->receiveConversation(['from' => 'robin@customer.example.org']);
        $other_draft = $this->replyDraft($other);
        request()->merge(['conversation_id' => $other->id, 'thread_id' => $other_draft->id, 'body' => '<p>Other draft</p>']);

        $response = app(ConversationReplies::class)->saveDraft([
            'mailbox_id' => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'body' => '<p>This draft</p>',
        ], $this->agent);

        $this->assertSuccess($response);
        $this->assertNotSame($other_draft->id, $response['thread_id']);
        $this->assertSame('<p>This draft</p>', Thread::find($response['thread_id'])->body);
        $this->assertSame('<p>Not sent yet</p>', $other_draft->fresh()->body);
        $this->assertSame($other_draft->id, request()->thread_id);
        $this->assertSame('<p>Other draft</p>', request()->body);
    }

    public function testADraftSentElsewhereIsNotSentOrSavedAgain()
    {
        $conversation = $this->receiveConversation();
        $draft = $this->replyDraft($conversation);
        $this->assertSuccess($this->ajax('send_reply', ['conversation_id' => $conversation->id, 'thread_id' => $draft->id, 'body' => '<p>Sent</p>']));

        $message = 'Message has been already sent. Please discard this draft.';
        $this->assertError($message, $this->ajax('send_reply', ['conversation_id' => $conversation->id, 'thread_id' => $draft->id, 'body' => '<p>Again</p>']));
        $this->assertError($message, $this->ajax('save_draft', ['conversation_id' => $conversation->id, 'thread_id' => $draft->id, 'body' => '<p>Again</p>']));
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testRetryingTheSameDraftSubmissionDoesNotSendAgain()
    {
        $conversation = $this->receiveConversation();
        $draft = $this->replyDraft($conversation);
        $data = [
            'conversation_id' => $conversation->id,
            'thread_id' => $draft->id,
            'submission_key' => '28a3434c-9281-4a03-9912-cfcd96d680b1',
            'body' => '<p>Answer</p>',
        ];

        $this->assertSuccess($this->ajax('send_reply', $data));
        $this->assertSuccess($this->ajax('send_reply', $data));
        $this->assertSame(1, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->count());
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testRetryingANewConversationSubmissionDoesNotCreateAnotherConversation()
    {
        $data = [
            'is_create' => 1,
            'to' => ['casey@customer.example.org'],
            'subject' => 'A new question',
            'submission_key' => 'd08b16e8-bff9-4cb2-8746-040e273e1cdd',
            'body' => '<p>Answer</p>',
        ];

        $this->assertSuccess($this->ajax('send_reply', $data));
        $this->assertSuccess($this->ajax('send_reply', $data));
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testMissingReplyAttachmentKeepsDraftAndDoesNotNotify()
    {
        \Storage::fake(Attachment::getDiskName());
        $conversation = $this->receiveConversation();
        $draft = $this->replyDraft($conversation);
        [$attachment, $encrypted_id] = $this->upload();
        Attachment::getDisk()->delete($attachment->getStorageFilePath());

        $this->assertError('Error occurred. Please try again later.', $this->ajax('send_reply', [
            'conversation_id' => $conversation->id,
            'thread_id' => $draft->id,
            'body' => '<p>Answer</p>',
            'attachments' => [$encrypted_id],
            'attachments_all' => [$encrypted_id],
        ]));
        $this->assertSame(Thread::STATE_DRAFT, $draft->fresh()->state);
        $this->assertSame(2, $conversation->threads()->count());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testFailureAfterSavingReplyDataRollsBackAndRemovesEmbeddedFiles()
    {
        \Storage::fake(Attachment::getDiskName());
        $conversation = $this->receiveConversation();
        $draft = $this->replyDraft($conversation);
        $body = '<p>Answer <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/nXcAAAAASUVORK5CYII="></p>';
        [$removed_attachment, $encrypted_id] = $this->upload();
        $saw_embedded_file = false;

        \Eventy::addAction('thread.before_save_from_request', $fail_save = function () use (&$saw_embedded_file) {
            $embedded = Attachment::where('embedded', true)->first();
            $saw_embedded_file = $embedded && $embedded->fileExists();
            throw new \RuntimeException('Simulated save failure');
        });
        try {
            $this->assertError('Error occurred. Please try again later.', $this->ajax('send_reply', [
                'conversation_id' => $conversation->id,
                'thread_id' => $draft->id,
                'body' => $body,
                'attachments_all' => [$encrypted_id],
            ]));
        } finally {
            \Eventy::removeAction('thread.before_save_from_request', $fail_save);
        }

        $this->assertTrue($saw_embedded_file);
        $this->assertSame(Thread::STATE_DRAFT, $draft->fresh()->state);
        $this->assertSame(2, $conversation->threads()->count());
        $this->assertNotNull($removed_attachment->fresh());
        $this->assertSame([$removed_attachment->getStorageFilePath()], Attachment::getDisk()->allFiles(Attachment::DIRECTORY));
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testANewConversationNeedsAValidRecipient()
    {
        $response = $this->ajax('send_reply', ['is_create' => 1, 'to' => ['not-an-email'], 'subject' => 'Hello', 'body' => '<p>Hi</p>']);

        $this->assertError('Incorrect recipients', $response);
        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testAMessageOverTheSizeLimitIsRefused()
    {
        config(['app.max_message_size' => 1]);
        $conversation = $this->receiveConversation();
        [, $small] = $this->upload('small.txt', str_repeat('a', 100 * 1024));
        [, $big] = $this->upload('big.txt', str_repeat('a', 800 * 1024));
        // Images in the text are sent in the email too (App\Misc\EmbedImages): they count.
        [, $image] = $this->upload('image.png', str_repeat('a', 800 * 1024), true);

        $this->assertSuccess($this->ajax('send_reply', ['conversation_id' => $conversation->id, 'body' => '<p>Small file</p>', 'attachments' => [$small]]));

        $message = 'Message is too large — Max. Message Size: 1 MB. Please shorten your message or remove some attachments.';
        $this->assertError($message, $this->ajax('send_reply', ['conversation_id' => $conversation->id, 'body' => '<p>Big file</p>', 'attachments' => [$big]]));
        $this->assertError($message, $this->ajax('send_reply', ['conversation_id' => $conversation->id, 'body' => '<p>Big image</p>', 'embeds' => [$image]]));
        $this->assertError($message, $this->ajax('send_reply', ['conversation_id' => $conversation->id, 'body' => str_repeat('<p>Long text</p>', 60000)]));
        // Notes aren't sent.
        $this->assertSuccess($this->ajax('send_reply', ['conversation_id' => $conversation->id, 'is_note' => 1, 'body' => '<p>Big file</p>', 'attachments' => [$big]]));
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testSendingNeedsAccessToTheConversation()
    {
        $limited = $this->createUser(['permissions' => [User::PERM_ONLY_ASSIGNED_TICKETS => true]]);
        $this->mailbox->users()->attach($limited->id);
        $conversation = $this->receiveConversation();

        $this->assertError('Not enough permissions', $this->ajax('send_reply', ['conversation_id' => $conversation->id, 'body' => '<p>Hi</p>'], $limited));
        $this->assertError('Not enough permissions', $this->ajax('save_draft', ['conversation_id' => $conversation->id, 'body' => '<p>Hi</p>'], $limited));
        $this->assertError('Not enough permissions', $this->ajax('save_draft', ['conversation_id' => $conversation->id, 'body' => '<p>Hi</p>'], $this->createUser()));
        $this->assertSame(1, $conversation->threads()->count());
    }

    // Sending: variants.

    public function testReplyFromAnAliasToAnotherAddressWithASavedReply()
    {
        $conversation = $this->receiveConversation();

        $this->assertSuccess($this->ajax('send_reply', [
            'conversation_id' => $conversation->id,
            'to'              => 'casey@work.example.org',
            'from_alias'      => 'sales@example.org',
            'saved_reply_id'  => 7,
            'conv_history'    => 'none',
            'body'            => '<p>Answer</p>',
        ]));

        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertSame('sales@example.org', $reply->from);
        $this->assertSame(['casey@work.example.org'], $reply->getToArray());
        $this->assertEquals(7, $reply->saved_reply_id);
        $this->assertSame('none', $reply->getMeta(Thread::META_CONVERSATION_HISTORY));
    }

    public function testAPhoneNoteMakesAnEmailConversationAPhoneConversation()
    {
        $conversation = $this->receiveConversation();

        $this->assertSuccess($this->ajax('send_reply', ['conversation_id' => $conversation->id, 'type' => Conversation::TYPE_PHONE, 'is_note' => 1, 'body' => '<p>Called them.</p>']));

        $this->assertTrue($conversation->fresh()->isPhone());
        $this->assertCount(0, $this->sentEmails());
    }

    public function testReplyingToAPhoneConversationMakesItAnEmailConversation()
    {
        $this->assertSuccess($this->ajax('send_reply', [
            'type' => Conversation::TYPE_PHONE, 'is_create' => 1, 'is_note' => 1,
            'name' => 'Kim Caller', 'to_email' => 'kim@customer.example.org', 'subject' => 'Called', 'body' => '<p>Called us.</p>',
        ]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertTrue($conversation->isPhone());

        $this->assertSuccess($this->ajax('send_reply', ['conversation_id' => $conversation->id, 'body' => '<p>As discussed.</p>']));

        $conversation->refresh();
        $this->assertSame(Conversation::TYPE_EMAIL, (int) $conversation->type);
        $this->assertSame('kim@customer.example.org', $conversation->customer_email);
        $this->assertCount(1, $this->sentEmailsTo('kim@customer.example.org'));
    }

    public function testPhoneConversationForACustomerTheUserCannotSee()
    {
        config(['app.limit_user_customer_visibility' => true]);
        $other_mailbox = $this->createMailbox();
        $hidden = $this->receiveConversation(['from' => 'robin@customer.example.org'], $other_mailbox)->customer;

        $data = ['type' => Conversation::TYPE_PHONE, 'is_create' => 1, 'is_note' => 1, 'name' => (string) $hidden->id, 'subject' => 'Called', 'body' => '<p>Called.</p>'];
        $this->assertError('Inaccessible customer', $this->ajax('send_reply', $data));
        $this->assertError('Inaccessible customer', $this->ajax('save_draft', $data));
        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testCustomConversationNeedsABody()
    {
        $this->assertError('The body field is required.', $this->ajax('send_reply', ['type' => Conversation::TYPE_CUSTOM, 'is_create' => 1, 'body' => '']));

        // Modules set the fields it needs.
        \Eventy::addFilter('conversation.custom.validation_rules', function ($rules) {
            return $rules + ['subject' => 'required|string'];
        }, 20, 2);
        $response = $this->ajax('send_reply', ['type' => Conversation::TYPE_CUSTOM, 'is_create' => 1, 'body' => '<p>Custom</p>']);
        \Eventy::removeAllFilters('conversation.custom.validation_rules');

        $this->assertError('The subject field is required.', $response);
        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    /**
     * A custom conversation (made by modules) has no customer and sends nothing.
     */
    public function testCustomConversation()
    {
        \Eventy::addFilter('conversation.custom.identifier', function () {
            return 'Ticket';
        }, 20, 2);

        $this->assertSuccess($this->ajax('send_reply', ['type' => Conversation::TYPE_CUSTOM, 'is_create' => 1, 'subject' => 'Internal', 'body' => '<p>Custom</p>']));
        $this->assertStringContainsString('Ticket added', session('flash_warning_floating'));

        $this->assertSuccess($this->ajax('send_reply', ['type' => Conversation::TYPE_CUSTOM, 'is_create' => 1, 'subject' => 'Internal', 'body' => '<p>Custom 2</p>', 'after_send' => MailboxUser::AFTER_SEND_STAY]));
        $this->assertSame('<strong>Ticket added</strong>', session('flash_warning_floating'));

        \Eventy::removeAllFilters('conversation.custom.identifier');
        $conversations = Conversation::where('mailbox_id', $this->mailbox->id)->get();
        $this->assertCount(2, $conversations);
        $this->assertSame(Conversation::TYPE_CUSTOM, (int) $conversations[0]->type);
        $this->assertNull($conversations[0]->customer_id);
        $this->assertCount(0, $this->sentEmails());
    }

    public function testStayingAfterANoteOrAPhoneConversation()
    {
        $conversation = $this->receiveConversation();

        $this->assertSuccess($this->ajax('send_reply', ['conversation_id' => $conversation->id, 'is_note' => 1, 'body' => '<p>Note</p>', 'after_send' => MailboxUser::AFTER_SEND_STAY]));
        $this->assertSame('<strong>Note added</strong>', session('flash_warning_floating'));

        $this->assertSuccess($this->ajax('send_reply', [
            'type' => Conversation::TYPE_PHONE, 'is_create' => 1, 'is_note' => 1, 'name' => 'Kim Caller',
            'subject' => 'Called', 'body' => '<p>Called.</p>', 'after_send' => MailboxUser::AFTER_SEND_STAY,
        ]));
        $this->assertSame('<strong>Conversation created</strong>', session('flash_warning_floating'));
    }

    public function testForwardToTwoPeopleWithAFile()
    {
        $conversation = $this->receiveConversation();
        [, $file] = $this->upload();

        $this->assertSuccess($this->ajax('send_reply', [
            'conversation_id' => $conversation->id,
            'subtype'         => Thread::SUBTYPE_FORWARD,
            'to_email'        => ['robin@customer.example.org', 'pat@customer.example.org'],
            'body'            => '<p>FYI</p>',
            'attachments'     => [$file],
            'attachments_all' => [$file],
        ]));

        $forwards = Conversation::where('mailbox_id', $this->mailbox->id)->where('id', '!=', $conversation->id)->orderBy('id')->get();
        $this->assertSame(['robin@customer.example.org', 'pat@customer.example.org'], $forwards->pluck('customer_email')->all());
        foreach ($forwards as $forward) {
            $this->assertTrue((bool) $forward->has_attachments);
            $thread = $forward->threads()->first();
            $this->assertTrue((bool) $thread->has_attachments);
            $this->assertSame(['notes.txt'], $thread->attachments->pluck('file_name')->all());
        }
        // A forward note for each.
        $notes = $conversation->threads()->where('type', Thread::TYPE_NOTE)->orderBy('id')->get();
        $this->assertCount(2, $notes);
        $this->assertEquals($forwards[1]->id, $notes[1]->getMeta(Thread::META_FORWARD_CHILD_CONVERSATION_ID));
        $this->assertSame(['pat@customer.example.org'], $notes[1]->getToArray());
        $this->assertCount(1, $this->sentEmailsTo('robin@customer.example.org'));
        $this->assertCount(1, $this->sentEmailsTo('pat@customer.example.org'));
    }

    public function testSentSeparatelyToEachCustomerOnceWithTheFile()
    {
        $casey = $this->createCustomer('casey@customer.example.org');
        $casey->addEmail('casey@work.example.org', true);
        [, $file] = $this->upload();

        $this->assertSuccess($this->ajax('send_reply', [
            'is_create'              => 1,
            'multiple_conversations' => 1,
            'to'                     => ['robin@customer.example.org', 'casey@customer.example.org', 'casey@work.example.org'],
            'subject'                => 'Survey',
            'body'                   => '<p>Please answer.</p>',
            'attachments'            => [$file],
            'attachments_all'        => [$file],
        ]));

        $conversations = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id')->get();
        $this->assertSame(['robin@customer.example.org', 'casey@customer.example.org'], $conversations->pluck('customer_email')->all());
        foreach ($conversations as $conversation) {
            $this->assertSame(['notes.txt'], $conversation->threads()->first()->attachments->pluck('file_name')->all());
        }
        $this->assertCount(0, $this->sentEmailsTo('casey@work.example.org'));
    }

    // Drafts.

    public function testADraftLikeTheReplyJustSentIsNotSaved()
    {
        $conversation = $this->receiveConversation();
        $this->assertSuccess($this->ajax('send_reply', ['conversation_id' => $conversation->id, 'body' => '<p>Answer</p>']));

        $this->assertError("You've already sent this message just recently.", $this->ajax('save_draft', ['conversation_id' => $conversation->id, 'body' => '<p>Answer</p>']));
        $this->assertSame(0, $conversation->threads()->where('state', Thread::STATE_DRAFT)->count());
    }

    public function testANewConversationDraftOfASentConversationIsNotSaved()
    {
        $conversation = $this->receiveConversation();

        $response = $this->ajax('save_draft', ['conversation_id' => $conversation->id, 'is_create' => 1, 'to' => ['casey@customer.example.org'], 'body' => '<p>Draft</p>']);

        $this->assertError('Message has been already sent. Please discard this draft.', $response);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $conversation->fresh()->state);
    }

    public function testNewConversationDraftToSeveralPeopleWithAFile()
    {
        [$attachment, $file] = $this->upload();

        $response = $this->ajax('save_draft', [
            'is_create' => 1, 'to' => ['robin@customer.example.org', 'pat@customer.example.org'], 'cc' => ['boss@customer.example.org'],
            'subject'   => 'Draft', 'body' => '<p>Draft</p>', 'attachments' => [$file], 'attachments_all' => [$file],
        ]);

        $this->assertSuccess($response);
        $conversation = Conversation::find($response['conversation_id']);
        $this->assertNull($conversation->customer_id);
        $this->assertTrue((bool) $conversation->has_attachments);
        $this->assertSame(['boss@customer.example.org'], $conversation->getCcArray());
        $thread = Thread::find($response['thread_id']);
        $this->assertSame(['robin@customer.example.org', 'pat@customer.example.org'], $thread->getToArray());
        $this->assertTrue((bool) $thread->has_attachments);
        $this->assertEquals($thread->id, $attachment->fresh()->thread_id);
    }

    public function testReplyDraftToAnotherAddress()
    {
        $conversation = $this->receiveConversation();

        $response = $this->ajax('save_draft', ['conversation_id' => $conversation->id, 'to' => 'casey@work.example.org', 'body' => '<p>Draft</p>']);

        $this->assertSuccess($response);
        $this->assertSame(['casey@work.example.org'], Thread::find($response['thread_id'])->getToArray());
    }

    public function testNoteDraftOfANewConversation()
    {
        $response = $this->ajax('save_draft', ['is_create' => 1, 'is_note' => 1, 'to' => ['robin@customer.example.org'], 'subject' => 'Draft', 'body' => '<p>Note</p>']);

        $this->assertSuccess($response);
        $this->assertSame(Thread::TYPE_NOTE, (int) Thread::find($response['thread_id'])->type);
    }

    public function testPhoneConversationDraft()
    {
        $response = $this->ajax('save_draft', [
            'is_create' => 1, 'type' => Conversation::TYPE_PHONE, 'name' => 'Kim Caller', 'phone' => '+31 20 555 0199',
            'to_email'  => 'kim@customer.example.org', 'subject' => 'Called', 'body' => '<p>Called</p>',
        ]);

        $this->assertSuccess($response);
        $conversation = Conversation::find($response['conversation_id']);
        $this->assertSame(Conversation::TYPE_PHONE, (int) $conversation->type);
        $this->assertSame('kim@customer.example.org', $conversation->customer_email);
        $this->assertSame('Kim', $conversation->customer->first_name);
        $this->assertSame(['kim@customer.example.org'], Thread::find($response['thread_id'])->getToArray());
    }

    public function testForwardDraftAndOneEditedByAColleague()
    {
        $conversation = $this->receiveConversation();
        $response = $this->ajax('save_draft', [
            'conversation_id' => $conversation->id, 'subtype' => Thread::SUBTYPE_FORWARD, 'to_email' => 'robin@customer.example.org', 'body' => '<p>FYI</p>',
        ]);
        $this->assertSuccess($response);
        $draft = Thread::find($response['thread_id']);
        $this->assertSame(['robin@customer.example.org'], $draft->getToArray());
        $this->assertTrue($draft->isForward());
        $this->assertNull($draft->edited_by_user_id);

        $colleague = $this->createUser();
        $this->mailbox->users()->attach($colleague->id);
        $this->assertSuccess($this->ajax('save_draft', ['conversation_id' => $conversation->id, 'thread_id' => $draft->id, 'to_email' => 'robin@customer.example.org', 'body' => '<p>FYI, edited</p>'], $colleague));

        $draft->refresh();
        $this->assertEquals($colleague->id, $draft->edited_by_user_id);
        $this->assertNotNull($draft->edited_at);
        $this->assertEquals($this->agent->id, $draft->created_by_user_id);
    }

    public function testRemovedFilesAreDeletedButNotThoseOfOtherThreads()
    {
        $conversation = $this->receiveConversation();
        [$kept, $kept_id] = $this->upload('kept.txt');
        [$removed, $removed_id] = $this->upload('removed.txt');
        [$image, $image_id] = $this->upload('image.png', 'png', true);
        $foreign = Attachment::create('foreign.txt', 'text/plain', null, 'Other', null, false, $conversation->threads()->first()->id);

        $response = $this->ajax('save_draft', [
            'conversation_id' => $conversation->id,
            'body'            => '<p>Draft</p>',
            'attachments'     => [$kept_id, 'not-encrypted'],
            'embeds'          => [$image_id],
            'attachments_all' => [$kept_id, $removed_id, $image_id, encrypt($foreign->id)],
        ]);

        $this->assertSuccess($response);
        $this->assertNull(Attachment::find($removed->id));
        $this->assertNotNull(Attachment::find($foreign->id), 'Not this draft\'s to delete.');
        $this->assertEquals($response['thread_id'], $kept->fresh()->thread_id);
        $this->assertNotNull(Attachment::find($image->id));
    }

    // Discarding.

    public function testDiscardingAnUnsavedConversationFromAThreadGoesBackToIt()
    {
        $conversation = $this->receiveConversation();

        $response = $this->ajax('discard_draft', ['thread_id' => '', 'from_thread_id' => $conversation->threads()->first()->id]);

        $this->assertSuccess($response);
        $this->assertSame(route('conversations.view', ['id' => $conversation->id]), $response['redirect_url']);
    }

    public function testDiscardingSomeoneElsesDraftNeedsAccess()
    {
        $conversation = $this->receiveConversation();
        $draft = $this->replyDraft($conversation);

        $this->assertError('Not enough permissions', $this->ajax('discard_draft', ['thread_id' => $draft->id], $this->createUser()));
        $this->assertNotNull(Thread::find($draft->id));
    }

    // Undo.

    public function testUndoingANewConversationMakesItADraftAgain()
    {
        $this->assertSuccess($this->ajax('send_reply', ['is_create' => 1, 'to' => ['robin@customer.example.org'], 'subject' => 'Hello', 'body' => '<p>Hi</p>']));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $thread = $conversation->threads()->first();

        $response = $this->actingAs($this->agent)->get('/conversation/undo-reply/'.$thread->id.'/'.csrf_token());

        $conversation->refresh();
        $response->assertRedirect($conversation->url(null, null, ['show_draft' => $thread->id]));
        $this->assertSame(Conversation::STATE_DRAFT, (int) $conversation->state);
        $this->assertEquals(Thread::STATE_DRAFT, $thread->fresh()->state);
        $this->assertTrue($conversation->folders()->where('type', \App\Folder::TYPE_DRAFTS)->exists());
    }

    public function testUndoingAForwardRemovesTheForwardedConversation()
    {
        $conversation = $this->receiveConversation();
        $this->assertSuccess($this->ajax('send_reply', [
            'conversation_id' => $conversation->id, 'subtype' => Thread::SUBTYPE_FORWARD, 'to_email' => ['robin@customer.example.org'], 'body' => '<p>FYI</p>',
        ]));
        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();
        $forward_id = $note->getMeta(Thread::META_FORWARD_CHILD_CONVERSATION_ID);
        $this->assertNotNull(Conversation::find($forward_id));

        $this->actingAs($this->agent)->get('/conversation/undo-reply/'.$note->id.'/'.csrf_token())->assertRedirect();

        $this->assertNull(Conversation::find($forward_id));
        $this->assertSame(0, Thread::where('conversation_id', $forward_id)->count());
        $this->assertEquals(Thread::STATE_DRAFT, $note->fresh()->state);
    }

    // The customer of a phone conversation.

    public function testPhoneCustomerByIdEmailOrPhone()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);
        $pat = $this->createCustomer('pat@customer.example.org', ['first_name' => 'Pat']);
        $pat->setPhones(['+31 20 555 0199']);
        $pat->save();

        // The name field holds a chosen customer's ID; a new email is added to them.
        $found = $this->phoneCustomer(['name' => (string) $robin->id, 'to_email' => 'robin@work.example.org']);
        $this->assertEquals($robin->id, $found['customer']->id);
        $this->assertSame('', $found['customer_email']);
        $this->assertNotNull(Customer::getByEmail('robin@work.example.org'));
        $this->assertEquals($robin->id, Customer::getByEmail('robin@work.example.org')->id);

        $found = $this->phoneCustomer(['name' => 'Robin', 'to_email' => 'robin@customer.example.org']);
        $this->assertEquals($robin->id, $found['customer']->id);
        $this->assertSame('robin@customer.example.org', $found['customer_email']);

        $found = $this->phoneCustomer(['name' => 'Someone', 'phone' => '+31 (20) 555-0199']);
        $this->assertEquals($pat->id, $found['customer']->id);
        $this->assertSame('pat@customer.example.org', $found['customer_email']);
    }

    public function testPhoneCustomerChosenGetsTheNewEmailOrDetails()
    {
        $robin = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin']);

        $found = $this->phoneCustomer(['customer_id' => $robin->id, 'name' => 'Robin Buyer', 'to_email' => 'robin@home.example.org']);
        $this->assertEquals($robin->id, $found['customer']->id);
        $this->assertSame('robin@home.example.org', $found['customer_email']);
        $this->assertEquals($robin->id, Customer::getByEmail('robin@home.example.org')->id);

        $found = $this->phoneCustomer(['customer_id' => $robin->id, 'name' => 'Robin Buyer', 'phone' => '+31 20 555 0111']);
        $this->assertEquals($robin->id, $found['customer']->id);
        // Only empty details are filled in.
        $this->assertSame('Customer', $robin->fresh()->last_name);
        $this->assertSame('+31 20 555 0111', $robin->fresh()->getPhones()[0]['value']);
    }

    public function testPhoneCustomerIsCreatedWhenNotFound()
    {
        $found = $this->phoneCustomer(['customer_id' => 999999, 'name' => 'Kim Caller', 'to_email' => 'kim@customer.example.org']);
        $this->assertSame('Kim', $found['customer']->first_name);
        $this->assertSame('kim@customer.example.org', $found['customer']->getMainEmail());

        $found = $this->phoneCustomer(['customer_id' => 999999, 'name' => 'Lee Caller', 'phone' => '+31 20 555 0222']);
        $this->assertSame('Lee', $found['customer']->first_name);
        $this->assertSame('', $found['customer_email']);
        $this->assertEmpty($found['customer']->getMainEmail());

        $this->assertNull($this->phoneCustomer(['name' => ''])['customer'], 'No empty customers.');
    }

    public function testPhoneCustomerTheUserCannotSee()
    {
        config(['app.limit_user_customer_visibility' => true]);
        $other_mailbox = $this->createMailbox();
        $hidden = $this->receiveConversation(['from' => 'robin@customer.example.org'], $other_mailbox)->customer;

        $this->assertSame('Inaccessible customer', $this->phoneCustomer(['customer_id' => $hidden->id, 'name' => 'Robin', 'to_email' => 'new@customer.example.org'])['msg']);
        $this->assertSame('Inaccessible customer', $this->phoneCustomer(['customer_id' => $hidden->id, 'name' => 'Robin', 'phone' => '+31 20 555 0333'])['msg']);
        $this->assertNull(Customer::getByEmail('new@customer.example.org'));
    }
}
