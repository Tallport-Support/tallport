<?php

namespace Tests\Feature;

use App\Conversation;
use App\Email;
use App\Livewire\ConversationComposer;
use App\Livewire\NewConversation;
use App\SendLog;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Delivery reports (bounces, delays, complaints, suppression notices): the
 * conversation is about the address that wasn't reached, the message shows
 * what failed and why with the original email, the address is flagged on the
 * customer's profile and in the composer until cleared.
 */
class DeliveryReportsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['email' => 'agent@example.org']);
        $this->mailbox = $this->createMailbox([$this->agent], ['email' => 'support@help.example.net']);
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_subject = 'We got your message';
        $this->mailbox->auto_reply_message = 'We will answer within a day.';
        $this->mailbox->save();
    }

    protected function receiveSample($file)
    {
        $this->receiveEmail($this->mailbox, file_get_contents(__DIR__.'/../Messages/'.$file));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function viewConversation(Conversation $conversation)
    {
        return $this->actingAs($this->agent)->followingRedirects()->get($conversation->url())->assertOk();
    }

    /**
     * Amazon SES's notice about an email another system sent: the conversation is the
     * customer's, the message says what happened, and the original is there to read.
     */
    public function testSuppressionNotice()
    {
        $other = $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'Jamie Doe <jamie.doe@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Earlier question']));
        $this->captured_mail->flush();

        $conversation = $this->receiveSample('delivery-report-ses-suppressed.eml');

        $this->assertSame('jamie.doe@customer.example.org', $conversation->customer_email);
        $this->assertSame('jamie.doe@customer.example.org', $conversation->customer->getMainEmail());
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->assertSame('complaints@email-abuse.amazonses.com', $thread->from, 'The report\'s sender stays on the message.');
        $this->assertTrue($thread->isBounce());
        $this->assertSame('suppressed', $thread->getSendStatusData()['delivery_report']['kind']);
        $this->assertSame(['Payment_Confirmation_for_Your_Example_Shop_Service.eml', 'Invoice-100001.pdf'], $thread->attachments()->orderBy('id')->pluck('file_name')->all());
        $this->assertCount(0, $this->sentEmails(), 'No auto reply, to the service or the customer.');

        $email = Email::where('email', 'jamie.doe@customer.example.org')->first();
        $this->assertSame('suppressed', $email->delivery_problem['kind']);
        $this->assertSame($thread->id, $email->delivery_problem['thread_id']);

        $this->viewConversation($conversation)
            ->assertSee('class="delivery-report__recipient">jamie.doe@customer.example.org</a>', false)
            ->assertSee('Not sent to')
            ->assertSee('Suppressed by Amazon SES')
            ->assertSee("The address is on the sending service's suppression list")
            ->assertSee('Other conversations: 1')
            // The original, to read in place.
            ->assertSee('Payment Confirmation for Your Example Shop Service')
            ->assertSee('Invoice Number: 100001')
            ->assertSee('Invoice-100001.pdf')
            // The report itself, collapsed.
            ->assertSee('Delivery Report')
            ->assertSee('Feedback-Type: abuse')
            // The customer's profile.
            ->assertSee('Bounced '.date('M j, Y', strtotime('now')).' (Suppressed)', false);
    }

    /**
     * A bounce of Tallport's own reply: it is marked Not delivered and linked.
     */
    public function testBounceOfAReply()
    {
        $this->mailbox->auto_reply_enabled = false;
        $this->mailbox->save();
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'Robin Lee <robin.lee@gmail.example.org>', 'to' => $this->mailbox->email]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>We sent you a new link.</p>',
        ]);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $reply_id = $this->sentEmailsTo('robin.lee@gmail.example.org')[0]->getId();
        $this->captured_mail->flush();

        $raw = str_replace('TP_reply-123-0123456789abcdef@help.example.net', $reply_id, file_get_contents(__DIR__.'/../Messages/delivery-report-tallport-reply.eml'));
        $this->receiveEmail($this->mailbox, $raw);
        $bounce = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();

        $this->assertNotEquals($conversation->id, $bounce->id);
        $this->assertSame($conversation->customer_id, $bounce->customer_id);
        $reply->refresh();
        $this->assertEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->send_status);
        $bounce_thread = $bounce->threads()->first();
        $this->assertSame($reply->id, $bounce_thread->getSendStatusData()['bounce_for_thread']);
        $this->assertSame('5.1.1', $bounce_thread->getSendStatusData()['status_code']);

        $this->viewConversation($bounce)
            ->assertSee('Not delivered to')
            ->assertSee('The address does not exist.')
            ->assertSee('Reported by Gmail')
            ->assertSeeInOrder(['<code>5.1.1</code>', '550-5.1.1 The email account that you tried to reach does not exist.'], false)
            ->assertSee('#thread-'.$reply->id, false)
            ->assertSee('We sent you a new link.');
        $this->viewConversation($conversation)->assertSee('Message not sent to customer')->assertSee('#thread-id='.$bounce_thread->id, false);
    }

    /**
     * A bounce naming a reply without the Message-ID's hash (made up) doesn't mark it.
     */
    public function testMadeUpBounceDoesNotMarkAReply()
    {
        $this->mailbox->auto_reply_enabled = false;
        $this->mailbox->save();
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'Robin Lee <robin.lee@gmail.example.org>', 'to' => $this->mailbox->email]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>We sent you a new link.</p>',
        ]);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $raw = str_replace('TP_reply-123-0123456789abcdef@help.example.net', 'TP_reply-'.$reply->id.'-0123456789abcdef@help.example.net', file_get_contents(__DIR__.'/../Messages/delivery-report-tallport-reply.eml'));

        $this->receiveEmail($this->mailbox, $raw);

        $this->assertNotEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->fresh()->send_status);
        $bounce = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $this->assertArrayNotHasKey('bounce_for_thread', $bounce->threads()->first()->getSendStatusData());
    }

    /**
     * A delay says so, but doesn't flag the address: the email may still arrive.
     */
    public function testDelay()
    {
        $conversation = $this->receiveSample('delivery-report-dsn-delayed.eml');

        $this->assertSame('alex.morgan@slow.example.org', $conversation->customer_email);
        $this->assertSame('Alex', $conversation->customer->first_name, 'Named as in the original.');
        $this->assertNull(Email::where('email', 'alex.morgan@slow.example.org')->first()->delivery_problem);
        $this->viewConversation($conversation)->assertSee('Delivery delayed to')->assertSee('The receiving server could not be reached.')->assertSee('Re: Invoice question');
    }

    /**
     * A plain-text notice from a mail server.
     */
    public function testPlainTextBounce()
    {
        $conversation = $this->receiveSample('delivery-report-plain-text.eml');

        $this->assertSame('lee.smith@customer.example.org', $conversation->customer_email);
        $this->assertSame('mailbox_full', Email::where('email', 'lee.smith@customer.example.org')->first()->delivery_problem['reason']);
        $this->assertCount(0, $this->sentEmails());
        $this->viewConversation($conversation)->assertSee('The mailbox is full.')->assertSee('Your subscription has been renewed.');
    }

    /**
     * The composer warns about a flagged recipient (sending still works); an agent clears the flag.
     */
    public function testWarningAndClearing()
    {
        $report = $this->receiveSample('delivery-report-dsn-bounce.eml');
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'Sam Taylor <sam.taylor@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Where is my order?']));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $this->assertNotEquals($report->id, $conversation->id);
        $email = Email::where('email', 'sam.taylor@customer.example.org')->first();
        $this->captured_mail->flush();
        $warning = 'An email to sam.taylor@customer.example.org failed on '.date('M j, Y').': The address does not exist.';

        Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $conversation])->call('open', 'reply')
            ->assertSee($warning)
            ->set('body', '<p>It is on its way.</p>')->call('send')->assertRedirect();
        $this->assertCount(1, $this->sentEmailsTo('sam.taylor@customer.example.org'));
        Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $report])->call('open', 'note')->assertDontSee($warning);
        $new = new Conversation();
        $new->mailbox = $this->mailbox;
        Livewire::actingAs($this->agent)->test(NewConversation::class, ['conversation' => $new, 'mailbox' => $this->mailbox])
            ->set('cc', 'sam.taylor@customer.example.org')->assertSee($warning);

        // An agent without access to the customer can't clear it.
        config(['app.limit_user_customer_visibility' => true]);
        $outsider = $this->createUser();
        $this->postAjax($outsider, '/customers/ajax', ['action' => 'clear_delivery_problem', 'email_id' => $email->id])->assertJson(['status' => 'error']);
        $this->assertNotNull($email->fresh()->delivery_problem);

        $this->postAjax($this->agent, '/customers/ajax', ['action' => 'clear_delivery_problem', 'email_id' => $email->id])->assertJson(['status' => 'success']);
        $this->assertNull($email->fresh()->delivery_problem);
        $this->viewConversation($conversation)->assertDontSee('customer-delivery-problem', false);
        Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $conversation])->call('open', 'reply')->assertDontSee($warning);
    }

    /**
     * The sidebar shows the flag with Clear.
     */
    public function testFlagOnTheProfile()
    {
        $conversation = $this->receiveSample('delivery-report-arf-complaint.eml');

        $this->assertSame('casey.jones@mail.example.org', $conversation->customer_email);
        $this->viewConversation($conversation)
            ->assertSee('Complaint from')
            ->assertSee('The recipient marked the email as spam.')
            ->assertSee('Bounced '.date('M j, Y').' (Complaint)', false)
            ->assertSee('customer-delivery-problem__clear', false);
        $this->actingAs($this->agent)->get(route('customers.update', ['id' => $conversation->customer_id]))->assertOk();
    }
}
