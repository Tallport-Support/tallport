<?php

namespace Tests\Feature;

use App\Attachment;
use App\Console\Commands\FetchEmails;
use App\Conversation;
use App\Mailbox;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * What tallport:fetch-emails does with each fetched email beyond the normal
 * loop (ReceiveMailTest, IncomingEmailEdgeCasesTest): threading across
 * mailboxes, Jira and duplicate Message-IDs, agents' emailed replies and
 * notes, and modules skipping emails.
 */
class FetchEmailsProcessMessageTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    /**
     * A 1x1 PNG.
     */
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['email' => 'agent@example.org']);
        $this->mailbox = $this->createMailbox([$this->agent], ['email' => 'support@example.org']);
    }

    protected function receiveFromCustomer(array $options = [], $mailbox = null, array $all_mailboxes = [])
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $output = $this->receiveEmail($mailbox, $this->makeEmail(array_merge([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $mailbox->email,
            'subject' => 'Question about my order',
        ], $options)), $all_mailboxes);

        return [Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first(), $output];
    }

    /**
     * The agent replies in the UI; returns the reply's Message-ID.
     */
    protected function replyInUi(Conversation $conversation)
    {
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $conversation->mailbox_id, 'conversation_id' => $conversation->id, 'body' => '<p>Answer</p>',
        ]);
        $reply_id = $this->sentEmailsTo($conversation->customer_email)[0]->getId();
        $this->captured_mail->flush();

        return $reply_id;
    }

    /**
     * Customer email, agent reply (UI), customer answer: the agent gets a
     * notification, returned here.
     */
    protected function notificationForAgent()
    {
        [$conversation] = $this->receiveFromCustomer(['message_id' => 'first@customer.example.org']);
        $reply_id = $this->replyInUi($conversation);
        $this->receiveFromCustomer(['message_id' => 'second@customer.example.org', 'in_reply_to' => $reply_id, 'body' => 'Follow-up question']);
        $notification = $this->sentEmailsTo($this->agent->email)[0];
        $this->captured_mail->flush();

        return [$conversation->fresh(), $notification];
    }

    protected function agentRepliesByEmail($notification, array $options = [], $mailbox = null)
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $defaults = ['from' => $this->agent->email, 'to' => $mailbox->email, 'body' => 'Emailed answer'];
        if ($notification) {
            $defaults += ['subject' => 'Re: '.$notification->getSubject(), 'in_reply_to' => $notification->getId()];
        }

        return $this->receiveEmail($mailbox, $this->makeEmail(array_merge($defaults, $options)));
    }

    /**
     * A multipart/mixed email body: HTML referring to an inline image
     * (cid:logo), the image, and a PDF attachment.
     */
    protected function htmlWithAttachments($html)
    {
        return "--mixed\nContent-Type: multipart/related; boundary=\"related\"\n\n"
            ."--related\nContent-Type: text/html; charset=UTF-8\n\n$html\n"
            ."--related\nContent-Type: image/png; name=\"logo.png\"\nContent-Disposition: inline; filename=\"logo.png\"\nContent-ID: <logo>\nContent-Transfer-Encoding: base64\n\n".self::PNG."\n"
            ."--related--\n"
            ."--mixed\nContent-Type: application/pdf; name=\"invoice.pdf\"\nContent-Disposition: attachment; filename=\"invoice.pdf\"\nContent-Transfer-Encoding: base64\n\n".base64_encode('%PDF-1.4 invoice')."\n"
            ."--mixed--";
    }

    // Message-IDs.

    /**
     * Jira gives each email its own Message-ID but refers to the issue's
     * first one in In-Reply-To: the first is saved under the issue's ID, so
     * the follow-ups thread.
     */
    public function testJiraEmailsAreThreaded()
    {
        [$conversation] = $this->receiveFromCustomer([
            'from' => 'jira@company.atlassian.net', 'subject' => '[JIRA] (SUP-1) Printer on fire',
            'message_id' => 'JIRA.10001.1600000000000.55.1600000000999@Atlassian.JIRA',
        ]);
        $this->receiveFromCustomer([
            'from' => 'jira@company.atlassian.net', 'subject' => 'Re: [JIRA] (SUP-1) Printer on fire',
            'message_id' => 'JIRA.10001.1600000000000.56.1600000005555@Atlassian.JIRA',
            'in_reply_to' => 'JIRA.10001.1600000000000@Atlassian.JIRA', 'body' => 'Status changed to Done',
        ]);

        $this->assertSame(
            ['JIRA.10001.1600000000000@Atlassian.JIRA', 'JIRA.10001.1600000000000.56.1600000005555@Atlassian.JIRA'],
            $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->orderBy('id')->pluck('message_id')->all()
        );
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    /**
     * A different email reusing a fetched email's Message-ID is saved too,
     * under a generated Message-ID.
     */
    public function testNewEmailWithTheSameMessageIdIsSaved()
    {
        $this->receiveFromCustomer(['message_id' => 'reused@customer.example.org', 'subject' => 'First email']);
        [$conversation, $output] = $this->receiveFromCustomer(['message_id' => 'reused@customer.example.org', 'subject' => 'Another email', 'body' => 'Something else']);

        $this->assertSame('Another email', $conversation->subject);
        $this->assertSame(2, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $message_id = $conversation->threads()->first()->message_id;
        $this->assertTrue((bool) \MailHelper::isGeneratedMessageId($message_id));
        $this->assertStringContainsString('Generated artificial Message-ID: '.$message_id, $output);
    }

    // Bounces.

    /**
     * Mail servers put an empty Return-Path at the top of bounces.
     */
    public function testBounceDetectedByEmptyReturnPath()
    {
        $output = $this->receiveEmail($this->mailbox, "Return-Path: <>\r\n".$this->makeEmail([
            'from' => 'postmaster@mx.customer.example.org', 'to' => $this->mailbox->email,
            'subject' => 'Delivery failure', 'body' => 'Your message could not be delivered.',
        ]));

        $this->assertStringContainsString('Bounce detected by Return-Path header.', $output);
        $thread = Thread::where('from', 'postmaster@mx.customer.example.org')->first();
        $this->assertTrue($thread->isBounce());
    }

    // Customer replies and mailboxes.

    /**
     * A customer's reply to an agent's reply, sent to another mailbox, starts
     * a conversation there (#5350).
     */
    public function testReplyToAnotherMailboxsReplyStartsConversationThere()
    {
        $sales = $this->createMailbox([$this->agent], ['email' => 'sales@example.org']);
        [$conversation] = $this->receiveFromCustomer();
        $reply_id = $this->replyInUi($conversation);

        $this->receiveFromCustomer(['subject' => 'Re: Question about my order', 'in_reply_to' => $reply_id, 'references' => [$reply_id, 'unknown@customer.example.org'], 'body' => 'Asking sales'], $sales);

        $this->assertSame(1, Conversation::where('mailbox_id', $sales->id)->count());
        $this->assertSame(0, $conversation->threads()->where('body', 'like', '%Asking sales%')->count());
    }

    /**
     * Without In-Reply-To, an email referring to a conversation in another
     * mailbox starts a conversation in this one.
     */
    public function testReferenceToAnotherMailboxStartsConversation()
    {
        $sales = $this->createMailbox([$this->agent], ['email' => 'sales@example.org']);
        [$conversation] = $this->receiveFromCustomer(['message_id' => 'to-support@customer.example.org']);

        $this->receiveFromCustomer(['subject' => 'Also for sales', 'references' => ['to-support@customer.example.org'], 'body' => 'Asking sales'], $sales);

        $this->assertSame('Also for sales', Conversation::where('mailbox_id', $sales->id)->value('subject'));
        $this->assertSame(1, $conversation->threads()->count());
    }

    /**
     * A conversation moved to another mailbox: the customer's reply, still
     * sent to the first mailbox, joins it (#5590).
     */
    public function testReplyJoinsConversationMovedToAnotherMailbox()
    {
        $sales = $this->createMailbox([$this->agent], ['email' => 'sales@example.org']);
        [$conversation] = $this->receiveFromCustomer();
        $reply_id = $this->replyInUi($conversation);
        $conversation->moveToMailbox($sales, $this->agent);

        $this->receiveFromCustomer(['subject' => 'Re: Question about my order', 'in_reply_to' => $reply_id, 'body' => 'Thanks for moving it']);

        $this->assertSame(1, $conversation->threads()->where('body', 'like', '%Thanks for moving it%')->count());
        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    /**
     * An email to two mailboxes, fetched by sales first: support's copy has a
     * generated Message-ID. A reply to the original email, fetched by
     * support, joins support's conversation (#5308).
     */
    public function testReplyFindsThisMailboxsCopyByGeneratedMessageId()
    {
        $sales = $this->mailboxesReceivingEmail();
        $this->receiveFromCustomer(['to' => 'sales@example.org', 'cc' => 'support@example.org', 'message_id' => 'both@customer.example.org'], $sales, [$sales, $this->mailbox]);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();

        $this->receiveFromCustomer(['subject' => 'Re: Question about my order', 'in_reply_to' => 'both@customer.example.org', 'body' => 'More details for support']);

        $this->assertSame(1, $conversation->threads()->where('body', 'like', '%More details for support%')->count());
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    /**
     * A reply referring to another mailbox's copy of an email (its generated
     * Message-ID) joins the conversation this mailbox has of that email, or
     * starts one if it has none.
     */
    public function testReplyToAnotherMailboxsCopyFindsTheOriginal()
    {
        $sales = $this->mailboxesReceivingEmail();
        $this->receiveFromCustomer(['cc' => 'sales@example.org', 'message_id' => 'both@customer.example.org'], null, [$this->mailbox, $sales]);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $sales_copy_id = Thread::whereHas('conversation', fn ($query) => $query->where('mailbox_id', $sales->id))->value('message_id');
        $this->assertTrue((bool) \MailHelper::isGeneratedMessageId($sales_copy_id));

        $this->receiveFromCustomer(['subject' => 'Re: Question about my order', 'in_reply_to' => $sales_copy_id, 'body' => 'Reply to the copy']);

        $this->assertSame(1, $conversation->threads()->where('body', 'like', '%Reply to the copy%')->count());

        $conversation->deleteForever();
        [$new_conversation] = $this->receiveFromCustomer(['subject' => 'Re: Question about my order', 'in_reply_to' => $sales_copy_id, 'body' => 'Again']);

        $this->assertNotNull($new_conversation);
        $this->assertSame(1, $new_conversation->threads()->count());
        $this->assertSame(1, Conversation::where('mailbox_id', $sales->id)->first()->threads()->count(), "Sales' conversation is not changed.");
    }

    /**
     * Support and a sales mailbox (returned), both fetching email.
     */
    protected function mailboxesReceivingEmail()
    {
        $incoming = ['in_protocol' => Mailbox::IN_PROTOCOL_IMAP, 'in_server' => 'imap.example.org', 'in_port' => 993, 'in_username' => 'u', 'in_password' => 'p'];
        $this->mailbox->fill($incoming)->save();

        return $this->createMailbox([$this->agent], ['email' => 'sales@example.org'] + $incoming);
    }

    /**
     * Mailboxes that don't receive email get no copy.
     */
    public function testNoCopyForMailboxWithoutIncomingEmail()
    {
        $this->mailbox->fill(['in_protocol' => Mailbox::IN_PROTOCOL_IMAP, 'in_server' => 'imap.example.org', 'in_port' => 993, 'in_username' => 'u', 'in_password' => 'p'])->save();
        $sales = $this->createMailbox([$this->agent], ['email' => 'sales@example.org']);

        $this->receiveFromCustomer(['cc' => 'sales@example.org'], null, [$this->mailbox, $sales]);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(0, Conversation::where('mailbox_id', $sales->id)->count());
    }

    public function testCustomerReplyRestoresDeletedConversation()
    {
        [$conversation] = $this->receiveFromCustomer(['message_id' => 'first@customer.example.org']);
        $conversation->state = Conversation::STATE_DELETED;
        $conversation->save();

        $this->receiveFromCustomer(['subject' => 'Re: Question about my order', 'in_reply_to' => 'first@customer.example.org', 'body' => 'Still there?']);

        $this->assertEquals(Conversation::STATE_PUBLISHED, $conversation->fresh()->state);
        $this->assertSame(2, $conversation->threads()->count());
    }

    /**
     * With app.use_mail_date_on_fetching, threads get the email's date.
     */
    public function testMailDateIsUsedWhenConfigured()
    {
        config(['app.use_mail_date_on_fetching' => true]);
        [$conversation] = $this->receiveFromCustomer(['date' => 'Mon, 05 Oct 2026 08:30:00 +0000']);
        $reply_id = $this->replyInUi($conversation);
        $this->receiveFromCustomer(['message_id' => 'second@customer.example.org', 'in_reply_to' => $reply_id, 'date' => 'Mon, 05 Oct 2026 09:00:00 +0000', 'body' => 'Follow-up question']);
        $notification = $this->sentEmailsTo($this->agent->email)[0];

        $this->agentRepliesByEmail($notification, ['date' => 'Mon, 05 Oct 2026 10:15:00 +0000']);

        $this->assertSame('2026-10-05 08:30:00', $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->orderBy('id')->first()->created_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $agent_reply = $conversation->threads()->where('created_by_user_id', $this->agent->id)->where('source_type', Thread::SOURCE_TYPE_EMAIL)->first();
        $this->assertSame('2026-10-05 10:15:00', $agent_reply->created_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    /**
     * When the thread can't be saved, the new conversation isn't left behind
     * empty.
     */
    public function testConversationIsRemovedWhenItsFirstThreadCannotBeSaved()
    {
        Thread::saving(function ($thread) {
            if ($thread->type == Thread::TYPE_CUSTOMER) {
                throw new \Exception('Disk full');
            }
        });

        [$conversation, $output] = $this->receiveFromCustomer();

        $this->assertNull($conversation);
        $this->assertStringContainsString('Disk full', $output);
    }

    /**
     * Base64 images in an email's HTML become attachments.
     */
    public function testBase64ImagesBecomeAttachments()
    {
        [$conversation] = $this->receiveFromCustomer(['html' => true, 'body' => '<html><body><p>Screenshot:</p><img src="data:image/png;base64,'.self::PNG.'"></body></html>']);

        $thread = $conversation->threads()->first();
        $this->assertStringNotContainsString('base64', $thread->body);
        $attachment = Attachment::where('mime_type', 'image/png')->where('embedded', true)->first();
        $this->assertNotNull($attachment);
        $this->assertStringContainsString($attachment->url(), $thread->body);
    }

    /**
     * A base64 image's attachment belongs to its thread, so it is deleted
     * with the conversation.
     */
    public function testBase64ImageIsDeletedWithTheConversation()
    {
        [$conversation] = $this->receiveFromCustomer(['html' => true, 'body' => '<html><body><img src="data:image/png;base64,'.self::PNG.'"></body></html>']);
        $attachment = Attachment::where('mime_type', 'image/png')->where('embedded', true)->first();

        $this->assertEquals($conversation->threads()->first()->id, $attachment->thread_id);

        $conversation->deleteForever();

        $this->assertNull(Attachment::find($attachment->id));
    }

    // Modules.

    public function testModuleCanSkipCustomerEmail()
    {
        \Eventy::addFilter('fetch_emails.should_save_thread', function ($save, $data) {
            return $data['subject'] == 'Spam offer' ? false : $save;
        }, 20, 2);

        [$conversation, $output] = $this->receiveFromCustomer(['subject' => 'Spam offer']);

        $this->assertNull($conversation);
        $this->assertStringContainsString('Hook fetch_emails.should_save_thread returned false. Skipping message.', $output);
    }

    public function testModuleCanSkipAgentsEmailedReply()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $threads = $conversation->threads()->count();
        \Eventy::addFilter('fetch_emails.should_save_thread', function ($save, $data) {
            return $data['message_from_customer'] ? $save : false;
        }, 20, 2);

        $output = $this->agentRepliesByEmail($notification);

        $this->assertSame($threads, $conversation->threads()->count());
        $this->assertStringContainsString('Hook fetch_emails.should_save_thread returned false. Skipping message.', $output);
    }

    // Agents' replies to notifications.

    public function testNotificationReplyForUnknownUserIsDropped()
    {
        [$conversation] = $this->receiveFromCustomer();
        $thread_id = $conversation->threads()->first()->id;
        $message_id = 'FS_notify-'.$thread_id.'-999999-'.\MailHelper::getMessageIdHash($thread_id).'@example.org';

        $output = $this->agentRepliesByEmail(null, ['subject' => 'Re: Question about my order', 'in_reply_to' => $message_id]);

        $this->assertStringContainsString('User not found: 999999', $output);
        $this->assertSame(1, $conversation->threads()->count());
    }

    /**
     * An agent's reply to a notification of a thread that is gone: the agent
     * is told.
     */
    public function testNotificationReplyForMissingThreadIsRefused()
    {
        $message_id = 'FS_notify-999999-'.$this->agent->id.'-'.\MailHelper::getMessageIdHash(999999).'@example.org';

        $output = $this->agentRepliesByEmail(null, ['subject' => 'Re: Gone', 'in_reply_to' => $message_id]);

        $this->assertStringContainsString('previous thread could not be determined', $output);
        $this->assertSame(0, Thread::where('body', 'like', '%Emailed answer%')->count());
        $emails = $this->sentEmailsTo($this->agent->email);
        $this->assertCount(1, $emails);
        $this->assertStringContainsString('The conversation you replied to could not be found.', $emails[0]->getBody());
    }

    /**
     * An agent forwarding a notification to another mailbox starts a
     * conversation there (#4515).
     */
    public function testNotificationForwardedToAnotherMailboxStartsConversation()
    {
        $sales = $this->createMailbox([$this->agent], ['email' => 'sales@example.org']);
        [$conversation, $notification] = $this->notificationForAgent();
        $threads = $conversation->threads()->count();

        $output = $this->agentRepliesByEmail($notification, ['subject' => 'Fwd: '.$notification->getSubject(), 'body' => 'Sales, can you take this?'], $sales);

        $this->assertStringContainsString('Forwarded email notification detected. Creating a new conversation.', $output);
        $this->assertSame($threads, $conversation->threads()->count());
        $forwarded = Conversation::where('mailbox_id', $sales->id)->first();
        $this->assertSame($this->agent->email, $forwarded->customer_email);
        $this->assertStringContainsString('Sales, can you take this?', $forwarded->threads()->first()->body);
    }

    /**
     * Who the conversation is assigned to after an agent's emailed reply, by
     * the mailbox's setting.
     *
     * @dataProvider assigneeSettings
     */
    public function testAssigneeAfterEmailedReply($setting, $assigned_before, $assigned_after)
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $other = $this->createUser();
        $this->mailbox->users()->attach($other->id);
        $users = ['nobody' => null, 'agent' => $this->agent->id, 'other' => $other->id];
        $this->mailbox->ticket_assignee = $setting;
        $this->mailbox->save();
        $conversation->user_id = $users[$assigned_before];
        $conversation->save();

        $this->agentRepliesByEmail($notification);

        $this->assertEquals($users[$assigned_after], $conversation->fresh()->user_id);
        $this->assertSame(1, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->where('body', 'like', '%Emailed answer%')->count());
    }

    /**
     * "Anyone" unassigns the conversation: it is in Unassigned.
     */
    public function testEmailedReplyUnassignsWhenAnyoneIsAssignee()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $this->mailbox->ticket_assignee = Mailbox::TICKET_ASSIGNEE_ANYONE;
        $this->mailbox->save();
        $conversation->user_id = $this->agent->id;
        $conversation->save();

        $this->agentRepliesByEmail($notification);

        $conversation->refresh();
        $this->assertNull($conversation->user_id);
        $this->assertEquals(\App\Folder::TYPE_UNASSIGNED, $conversation->folder->type);
    }

    public static function assigneeSettings()
    {
        return [
            'replying agent if unassigned' => [Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED, 'nobody', 'agent'],
            'assigned stays'              => [Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED, 'other', 'other'],
            'replying agent'              => [Mailbox::TICKET_ASSIGNEE_REPLYING, 'other', 'agent'],
            'keep current'                => [Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT, 'other', 'other'],
        ];
    }

    /**
     * An agent's emailed reply with an inline image and an attachment: the
     * image is shown in place, the conversation's Cc gets the reply too.
     */
    public function testEmailedReplyWithAttachments()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $conversation->setCc(['boss@customer.example.org']);
        $conversation->save();

        $this->agentRepliesByEmail($notification, [
            'headers' => ['Content-Type' => 'multipart/mixed; boundary="mixed"'],
            'body'    => $this->htmlWithAttachments('<p>See the logo <img src="cid:logo"> and invoice. <img src="data:image/png;base64,'.self::PNG.'"></p>'),
        ]);

        $thread = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->where('source_type', Thread::SOURCE_TYPE_EMAIL)->first();
        $this->assertNotNull($thread);
        $this->assertStringNotContainsString('cid:logo', $thread->body);
        $this->assertStringNotContainsString('base64', $thread->body);
        $logo = Attachment::where('thread_id', $thread->id)->where('file_name', 'logo.png')->first();
        $this->assertTrue((bool) $logo->embedded);
        $this->assertStringContainsString($logo->url(), $thread->body);
        $this->assertTrue((bool) $thread->has_attachments);
        $this->assertTrue((bool) $conversation->fresh()->has_attachments);
        $this->assertContains('boss@customer.example.org', $thread->getCcArray());
    }

    /**
     * An agent's email adding a note, with attachments.
     */
    public function testEmailedNoteWithAttachments()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $this->agentRepliesByEmail($notification, ['message_id' => 'agent-answer@agent.example.org']);
        $this->captured_mail->flush();

        $this->agentRepliesByEmail($notification, [
            'in_reply_to' => 'agent-answer@agent.example.org',
            'headers'     => ['Content-Type' => 'multipart/mixed; boundary="mixed"'],
            'body'        => $this->htmlWithAttachments('<p>Note with <img src="cid:logo"> <img src="data:image/png;base64,'.self::PNG.'"></p>'),
        ]);

        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();
        $this->assertNotNull($note);
        $this->assertStringNotContainsString('cid:logo', $note->body);
        $this->assertStringNotContainsString('base64', $note->body);
        $this->assertTrue((bool) $note->has_attachments);
        $file_names = Attachment::where('thread_id', $note->id)->orderBy('file_name')->pluck('file_name')->all();
        $this->assertCount(3, $file_names, 'The files and the base64 image.');
        $this->assertSame(['invoice.pdf', 'logo.png'], array_slice($file_names, 1));
        $this->assertTrue((bool) $conversation->fresh()->has_attachments);
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    /**
     * Only the agent's text above the notification's separator is kept, also
     * with Outlook's reply header above it (#5545).
     */
    public function testEmailedReplyIsCutAtNotificationSeparator()
    {
        [$conversation, $notification] = $this->notificationForAgent();

        $this->agentRepliesByEmail($notification, [
            'html' => true,
            'body' => '<html><body><p>Short answer</p><hr style="display:inline-block"><div id="divRplyFwdMsg"><b>From:</b> Support<br><b>Sent:</b> Monday</div>'
                .'<div>'.\MailHelper::REPLY_SEPARATOR_NOTIFICATION.'</div><p>Follow-up question</p></body></html>',
        ]);

        $thread = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->where('source_type', Thread::SOURCE_TYPE_EMAIL)->first();
        $this->assertStringContainsString('Short answer', $thread->body);
        $this->assertStringNotContainsString('divRplyFwdMsg', $thread->body);
        $this->assertStringNotContainsString('Follow-up question', $thread->body);
    }

    /**
     * An agent forwarding a customer's email in (@fwd) from an alternate
     * email address of theirs.
     */
    public function testFwdCommandFromAlternateEmail()
    {
        $this->agent->emails = 'agent.home@example.org';
        $this->agent->save();

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'    => 'agent.home@example.org',
            'to'      => $this->mailbox->email,
            'subject' => 'Fwd: Broken zipper',
            'body'    => "@fwd\n\n---------- Forwarded message ---------\nFrom: Robin Buyer <robin@customer.example.org>\nSubject: Broken zipper\n\nThe zipper broke.",
        ]));

        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertSame('robin@customer.example.org', $conversation->customer_email);
        $this->assertSame('Broken zipper', $conversation->subject);
    }

    // Reply separation.

    /**
     * The mailbox's own reply separator cuts off the quote.
     */
    public function testMailboxReplySeparator()
    {
        $this->mailbox->before_reply = '## Reply above ##';
        $this->mailbox->save();
        [$conversation] = $this->receiveFromCustomer(['message_id' => 'first@customer.example.org']);

        $this->receiveFromCustomer(['in_reply_to' => 'first@customer.example.org', 'body' => "New details\n## Reply above ##\nOld text"]);

        $body = $conversation->threads()->orderBy('id', 'desc')->first()->body;
        $this->assertStringContainsString('New details', $body);
        $this->assertStringNotContainsString('Old text', $body);
    }

    /**
     * With app.alternative_reply_separation, only the separator hashed for
     * the email replied to is used.
     */
    public function testHashedReplySeparator()
    {
        config(['app.alternative_reply_separation' => true]);
        [$conversation] = $this->receiveFromCustomer();
        $reply_id = $this->replyInUi($conversation);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $separator = \MailHelper::getHashedReplySeparator($reply->getMessageId($this->mailbox));

        $this->receiveFromCustomer([
            'html' => true, 'in_reply_to' => $reply_id,
            'body' => '<html><body><p>Above the hashed one</p><div class="gmail_quote">gmail</div><div id="'.$separator.'"></div><p>Quoted answer</p></body></html>',
        ]);

        $body = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->orderBy('id', 'desc')->first()->body;
        $this->assertStringContainsString('Above the hashed one', $body);
        $this->assertStringContainsString('gmail', $body, 'Other separators are not used.');
        $this->assertStringNotContainsString('Quoted answer', $body);
    }

    /**
     * Proton puts the quote before a second <html> part.
     */
    public function testProtonMailQuoteIsCutOff()
    {
        $body = (new FetchEmails())->separateReply('<p>Proton reply</p><div class="protonmail_quote">Quoted</div><html><body>Old</body></html>', true, true);

        $this->assertStringContainsString('Proton reply', $body);
        $this->assertStringNotContainsString('Quoted', $body);
        $this->assertStringNotContainsString('Old', $body);
    }

    /**
     * NetEase/Coremail mail (163.com, 126.com, yeah.net) quotes in its own ways; only the
     * customer's text is kept, without the CoremailReplyPreprocess module.
     *
     * @dataProvider coremailReplies
     */
    public function testCoremailQuotesAreCutOff($body, $is_html)
    {
        \Eventy::removeAllFilters('fetch_emails.separate_reply.preprocess_body');

        $reply = (new FetchEmails())->separateReply($body, $is_html, true);

        $this->assertStringContainsString('已经搞定了，谢谢', $reply);
        $this->assertStringNotContainsString('Quoted answer', $reply);
        $this->assertStringNotContainsString('回复的原邮件', $reply);
        $this->assertStringNotContainsString('写道', $reply);
    }

    /**
     * China Mobile's 139.com webmail: its quote after <hr id="replySplit"> (the 原始邮件 line in it
     * has non-breaking spaces, so QQ's separator doesn't match).
     */
    public function testMail139QuoteIsCutOff()
    {
        $body = '<div style="color: #000000;">您好，</div><div>非常感谢</div><div id="signContainer"></div><hr id="replySplit">'
            .'<div id="reply139content"><div id="mainReplyContent"><div>------------------&nbsp;原始邮件&nbsp;------------------</div>'
            .'<div><b>发件人:</b>&nbsp;Support &lt;support@example.org&gt;</div><div>Quoted answer</div></div></div>';

        $reply = (new FetchEmails())->separateReply($body, true, true);

        $this->assertStringContainsString('非常感谢', $reply);
        $this->assertStringNotContainsString('原始邮件', $reply);
        $this->assertStringNotContainsString('Quoted answer', $reply);
    }

    /**
     * QQ Mail's newer quote: an "Original" line above a From/Sent Time table (in plain text too).
     *
     * @dataProvider qqReplies
     */
    public function testQqOriginalQuoteIsCutOff($body, $is_html)
    {
        $reply = (new FetchEmails())->separateReply($body, $is_html, true);

        $this->assertStringContainsString('没有看到', $reply);
        $this->assertStringNotContainsString('Original', $reply);
        $this->assertStringNotContainsString('原始邮件', $reply);
        $this->assertStringNotContainsString('Quoted answer', $reply);
    }

    public static function qqReplies()
    {
        $table = '<table data-uneditable="true" style="line-height: 20px;"><tbody><tr><td><div><span>From:</span><span>Support</span> <span>&lt;support@example.org&gt;</span></div><div><span>Sent Time:</span><span>Oct 9, 2026 10:01</span></div></td></tr></tbody></table><div><br></div><div>Quoted answer</div>';

        return [
            'English' => [
                '<p><span>没有看到你说的 <b>Change Payment Method</b>.</span></p><div><br></div><article style="line-height: 1.43;"><div style="display:flex;align-items:center;padding-top:8px" contenteditable="false">'
                ."\n        ".'<div style="color:#959DA6;font-size:12px;line-height:30px">Original</div>'."\n        ".'<hr style="flex-grow:1">'."\n      ".'</div>'.$table.'</article>',
                true,
            ],
            'Chinese' => ['<p>没有看到</p><article><div style="display:flex" contenteditable="false"><div style="color:#959DA6">原始邮件</div><hr></div>'.$table.'</article>', true],
            'Plain text' => ["没有看到你说的 Change Payment Method.\r\n\r\nyanglei\r\n\r\n         Original\r\n         \r\n       \r\nFrom:Support <support@example.org>\r\nSent Time:Oct 9, 2026 10:01\r\n\r\nQuoted answer", false],
        ];
    }

    public static function coremailReplies()
    {
        $card = '<div style="margin-bottom:1em;font-size:12px"><table><tr><td>发件人</td><td>Support &lt;support@example.org&gt;</td></tr><tr><td>主题</td><td>Re: Question</td></tr></table></div><div>Quoted answer</div>';

        return [
            'Mail Master' => ['<div>已经搞定了，谢谢</div><div class="ntes-mailmaster-quote" style="padding-top: 1px"><div style="margin-top: 2em">---- 回复的原邮件 ----</div>'.$card.'</div>', true],
            'Mail Master, more classes' => ['<div>已经搞定了，谢谢</div><div class="J-reply ntes-mailmaster-quote" style="padding-top: 1px"><div style="margin:2em 0 1em">---- 回复的原邮件 ----</div>'.$card.'</div>', true],
            'Webmail' => ['<div>已经搞定了，谢谢</div><div id="divNeteaseMailCard"></div><p>在 2026-10-03 18:28:31，"Support" &lt;support@example.org&gt; 写道：</p><blockquote id="isReplyContent" style="PADDING-LEFT: 1ex"><div>Quoted answer</div></blockquote>', true],
            'Plain text' => ["已经搞定了，谢谢\n\n---- Replied Message ----\n| From | Support<support@example.org> |\n| Date | 10/03/2026 15:42 |\n| To | casey@example.org |\n| Subject | Re: Question |\nQuoted answer", false],
        ];
    }

    public function testEncodedAttachmentName()
    {
        $command = new FetchEmails();

        $this->assertSame('Grüße.pdf', \Normalizer::normalize($command->processAttachmentName('=?UTF-8?Q?Gr=C3=BC=C3=9Fe.pdf?=')));
        $this->assertSame('plain.pdf', $command->processAttachmentName('plain.pdf'));
    }
}
