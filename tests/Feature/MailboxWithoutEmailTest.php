<?php

namespace Tests\Feature;

use App\Conversation;
use App\Jobs\SendNotificationToUsers;
use App\Mailbox;
use App\SendLog;
use App\Thread;
use App\WebChat\WebChat;
use Tests\FeatureTestCase;

/**
 * Email is a channel a mailbox may not have (Mailbox::hasEmail()): one without an address runs on
 * its other channels, and nothing that needs an address happens for it.
 */
class MailboxWithoutEmailTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser(['first_name' => 'Alex']);
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    /**
     * New Mailbox asks for a name only; the mailbox has no email until an address is added on its
     * Email page (and none again when it's cleared), and Email's other tabs come with one.
     */
    public function testANameIsEnoughAndEmailIsSetUpOnItsPage()
    {
        $this->actingAs($this->admin)->get(route('mailboxes'))->assertDontSee('id="new_mailbox_email"', false);
        $this->postForm($this->admin, route('mailboxes.create'), ['name' => 'Chats'])->assertRedirect(route('mailboxes.general', ['id' => 'chats']));
        $this->postForm($this->admin, route('mailboxes.create'), ['name' => 'More Chats'])->assertSessionHasNoErrors();
        $mailbox = Mailbox::where('slug', 'chats')->firstOrFail();
        $this->assertNull($mailbox->email);
        $this->assertFalse($mailbox->hasEmail() || $mailbox->isInActive() || $mailbox->isOutActive());
        $this->assertSame([], $mailbox->getEmails());

        // Its settings: Email not set up (a choice, not a problem), its page only the address.
        $this->actingAs($this->admin)->get(route('mailboxes.general', ['id' => 'chats']))->assertOk()
            ->assertSeeInOrder(['Channels', 'Email', 'Not set up', 'Website Chat'])->assertDontSee('mailbox-pages__problem', false);
        $this->actingAs($this->admin)->get(route('mailboxes.email', ['id' => 'chats']))->assertOk()
            ->assertSee('Without one, this mailbox doesn’t send or receive email.')
            ->assertDontSee('Sending Emails')->assertDontSee('This mailbox can&#039;t receive email yet.', false);
        foreach (['mailboxes.connection', 'mailboxes.connection.incoming', 'mailboxes.auto_reply'] as $tab) {
            $this->actingAs($this->admin)->get(route($tab, ['id' => 'chats']))->assertRedirect(route('mailboxes.email', ['id' => 'chats']));
        }
        $this->actingAs($this->admin)->get(route('mailboxes'))->assertSee('No email address');

        // An address: email, with its tabs; cleared: none again.
        $fields = ['from_name' => Mailbox::FROM_NAME_MAILBOX];
        $this->postForm($this->admin, route('mailboxes.email.save', ['id' => 'chats']), $fields + ['email' => 'chats@example.org'])->assertSessionHasNoErrors();
        $this->assertTrue($mailbox->fresh()->hasEmail());
        $this->actingAs($this->admin)->get(route('mailboxes.email', ['id' => 'chats']))->assertSee('Sending Emails');
        $this->postForm($this->admin, route('mailboxes.email.save', ['id' => 'chats']), $fields + ['email' => ''])->assertSessionHasNoErrors();
        $this->assertNull($mailbox->fresh()->email);
    }

    /**
     * Its agents' notifications still go out, from the app's own address and settings; a reply by
     * email (a conversation from before the address went) isn't sent, and says why; no auto reply;
     * New Conversation is a phone one.
     */
    public function testNothingThatNeedsAnAddress()
    {
        $mailbox = $this->createMailbox([$this->agent], ['name' => 'Shop']);
        $mailbox->auto_reply_enabled = true;
        $mailbox->auto_reply_subject = 'Thanks';
        $mailbox->auto_reply_message = '<p>We got it.</p>';
        $mailbox->save();
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => 'Order']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->firstOrFail();
        $mailbox->email = null;
        $mailbox->save();
        \Option::set('mail_from', 'tallport@help.example.org');

        (new SendNotificationToUsers(collect([$this->agent]), $conversation, $conversation->getThreads()))->withFakeQueueInteractions()->handle();
        $notification = $this->sentEmailsTo($this->agent->email)[0];
        $this->assertSame(['tallport@help.example.org'], array_keys($notification->getFrom()));
        $this->assertStringEndsWith('@help.example.org>', $notification->getHeaders()->get('Message-ID')->getFieldBody());

        $sent = count($this->sentEmails());
        $this->sendReply($this->agent, ['mailbox_id' => $mailbox->id, 'conversation_id' => $conversation->id, 'body' => 'On its way.']);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->latest('id')->firstOrFail();
        $this->assertEquals(SendLog::STATUS_SEND_ERROR, $reply->send_status);
        $this->assertSame('This mailbox has no email address.', $reply->getSendStatusData()['msg'] ?? null);
        $this->assertCount($sent, $this->sentEmails());

        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'dana@customer.example.org', 'to' => 'elsewhere@example.org', 'subject' => 'Hello']));
        $this->assertSame([], $this->sentEmailsTo('dana@customer.example.org'));

        $this->actingAs($this->agent)->get(route('conversations.create', ['mailbox_id' => $mailbox->id]))->assertOk()
            ->assertDontSee('id="email-conv-switch"', false)->assertSee('id="phone"', false);
    }

    /**
     * Its website chat doesn't offer replies by email, nor sends them.
     */
    public function testTheWebsiteChatWithoutRepliesByEmail()
    {
        $mailbox = $this->createMailbox([$this->agent], ['name' => 'Forma', 'email' => '']);
        WebChat::saveSettings($mailbox, ['enabled' => true, 'widget' => 'forma', 'origins' => ['https://www.forma.example']]);

        // (The window's config, as @js writes it.)
        $this->get('/t/w?widget=forma')->assertOk()->assertSee('\u0022emailReplies\u0022:false', false);
        $token = $this->postJson('/t/v?widget=forma')->json('token');
        $this->post('/t/e?widget=forma', ['email' => 'casey@customer.example.org'], ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->assertNotFound();
    }
}
