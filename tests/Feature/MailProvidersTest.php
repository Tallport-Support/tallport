<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\NewConversation;
use App\Mailbox;
use App\Misc\MailProviders;
use App\Option;
use App\SendLog;
use App\Thread;
use Livewire\Livewire;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Concerns\FakesMailProviders;
use Tests\FeatureTestCase;

/**
 * Sending through Amazon SES, Mailgun, Postmark and Resend by their APIs
 * (no service is contacted: Tests\Concerns\FakesMailProviders), their
 * settings, and threading on the Message-ID a service gives a sent email.
 */
class MailProvidersTest extends FeatureTestCase
{
    use FakesMailProviders;

    const SES_ID = '010001a2b3c4d5e6-11111111-2222-3333-4444-555555555555-000000';
    const POSTMARK_ID = 'b7bc2f4a-e38e-4336-af7d-e6c392c2f817';
    const RESEND_ID = '49a3999c-0ce1-4ea6-ab68-afcd6dc2e794';

    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAdmin();
        $this->mailbox = $this->createMailbox([$this->agent], ['email' => 'support@shop.example.com']);
        $this->fakeMailProviders();
    }

    public static function providers()
    {
        return [
            'Amazon SES' => [Mailbox::OUT_METHOD_SES, ['ses_key' => 'AKIATEST', 'ses_secret' => 'ses-secret', 'ses_region' => 'eu-west-1']],
            'Mailgun'    => [Mailbox::OUT_METHOD_MAILGUN, ['mailgun_domain' => 'mg.example.net', 'mailgun_secret' => 'key-test', 'mailgun_region' => 'eu']],
            'Postmark'   => [Mailbox::OUT_METHOD_POSTMARK, ['postmark_token' => 'pm-token', 'postmark_stream' => 'outbound']],
            'Resend'     => [Mailbox::OUT_METHOD_RESEND, ['resend_key' => 're_test']],
        ];
    }

    protected function receiveConversation()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $this->mailbox->email,
            'subject' => 'Question about my order',
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function reply(Conversation $conversation, $body = '<p>Our answer</p>')
    {
        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action'          => 'send_reply',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'body'            => $body,
        ])->json();
        $this->assertSame('success', $response['status'], json_encode($response));

        return $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->first();
    }

    /**
     * The email in a request, as sent: raw (SES, Mailgun) or the API's JSON fields.
     */
    protected function sentHeaders(array $request)
    {
        if (str_contains($request['url'], 'amazonaws.com')) {
            return base64_decode(json_decode($request['body'], true)['Content']['Raw']['Data']);
        }
        if (str_contains($request['url'], 'mailgun.net')) {
            return $request['body'];
        }
        $json = json_decode($request['body'], true);
        $headers = '';
        foreach ($json['Headers'] ?? [] as $header) {
            $headers .= $header['Name'].': '.$header['Value']."\n";
        }
        foreach ($json['headers'] ?? [] as $name => $value) {
            $headers .= $name.': '.$value."\n";
        }

        return $headers;
    }

    /**
     * @dataProvider providers
     */
    public function testReplyIsSentThroughTheServicesApi($out_method, array $settings)
    {
        $this->useMailProvider($this->mailbox, $out_method, $settings);
        $conversation = $this->receiveConversation();

        $reply = $this->reply($conversation);

        $this->assertCount(1, $this->provider_requests);
        $request = $this->provider_requests[0];
        $headers = $this->sentHeaders($request);
        $this->assertStringContainsString('Message-ID: <'.$reply->getMessageId().'>', $headers);
        $this->assertStringContainsString('X-Tallport-Mail-Type: customer.message', $headers);
        $send_log = SendLog::where('thread_id', $reply->id)->first();
        $this->assertEquals(SendLog::STATUS_ACCEPTED, $send_log->status);

        switch ($out_method) {
            case Mailbox::OUT_METHOD_SES:
                $this->assertSame('https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', $request['url']);
                $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIATEST/', $request['headers']['authorization']);
                $this->assertSame(self::SES_ID, $send_log->provider_message_id);
                break;
            case Mailbox::OUT_METHOD_MAILGUN:
                $this->assertSame('https://api.eu.mailgun.net/v3/mg.example.net/messages.mime', $request['url']);
                $this->assertSame('Basic '.base64_encode('api:key-test'), $request['headers']['authorization']);
                $this->assertNull($send_log->provider_message_id, 'Mailgun keeps the Message-ID.');
                break;
            case Mailbox::OUT_METHOD_POSTMARK:
                $this->assertSame('https://api.postmarkapp.com/email', $request['url']);
                $this->assertSame('pm-token', $request['headers']['x-postmark-server-token']);
                $this->assertSame('outbound', json_decode($request['body'], true)['MessageStream']);
                $this->assertSame(self::POSTMARK_ID, $send_log->provider_message_id);
                break;
            case Mailbox::OUT_METHOD_RESEND:
                $this->assertSame('https://api.resend.com/emails', $request['url']);
                $this->assertSame('Bearer re_test', $request['headers']['authorization']);
                $this->assertSame(self::RESEND_ID, $send_log->provider_message_id);
                break;
        }
    }

    /**
     * Amazon SES replaced the reply's Message-ID: the customer's reply names
     * only SES's, and still lands in the conversation.
     */
    public function testReplyToARewrittenMessageIdThreads()
    {
        $this->useMailProvider($this->mailbox, Mailbox::OUT_METHOD_SES, self::providers()['Amazon SES'][1]);
        $conversation = $this->receiveConversation();
        $this->reply($conversation);

        $ses_message_id = self::SES_ID.'@email.amazonses.com';
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'        => 'Casey Customer <casey@customer.example.org>',
            'to'          => $this->mailbox->email,
            'subject'     => 'Re: Question about my order',
            'in_reply_to' => '<'.$ses_message_id.'>',
            'references'  => '<'.$ses_message_id.'>',
            'body'        => 'Thanks, that helps.',
        ]));

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(2, $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->count());
    }

    /**
     * A conversation the agent started, sent through Postmark: the customer's
     * reply names Postmark's Message-ID (<id@mtasv.net>).
     */
    public function testReplyToAnAgentStartedConversationThreads()
    {
        $this->useMailProvider($this->mailbox, Mailbox::OUT_METHOD_POSTMARK, ['postmark_token' => 'pm-token']);
        $new = new Conversation();
        $new->mailbox = $this->mailbox;
        Livewire::actingAs($this->agent)->test(NewConversation::class, ['conversation' => $new, 'mailbox' => $this->mailbox])
            ->set('to', 'casey@customer.example.org')->set('subject', 'Your order')->set('body', '<p>It ships today.</p>')
            ->call('send')->assertRedirect();
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertCount(1, $this->provider_requests);

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'        => 'Casey Customer <casey@customer.example.org>',
            'to'          => $this->mailbox->email,
            'subject'     => 'Re: Your order',
            'in_reply_to' => '<'.self::POSTMARK_ID.'@mtasv.net>',
            'body'        => 'Great, thanks.',
        ]));

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(1, $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->count());
    }

    /**
     * Amazon SES by SMTP answers "250 Ok <id>": with "@email.amazonses.com" that is the
     * Message-ID its reports name (as in tests/Messages/delivery-report-ses-suppressed.eml).
     */
    public function testSesSmtpResponseIsTheMessageId()
    {
        $original_id = '010001a11909e1a3-4ce1a1b5-4a65-4876-be19-000000000002-000000';
        $sample = file_get_contents(__DIR__.'/../Messages/delivery-report-ses-suppressed.eml');
        $this->assertStringContainsString('Message-ID: <'.$original_id.'@email.amazonses.com>', $sample);

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport('email-smtp.us-east-1.amazonaws.com', 465, true);
        $parse = new \ReflectionMethod($transport, 'parseMessageId');
        $this->assertSame($original_id, $parse->invoke($transport, '250 Ok '.$original_id."\r\n"));

        $email = (new \Symfony\Component\Mime\Email())->from('support@shop.example.com')->to('jamie.doe@customer.example.org')->text('Hi');
        $email->getHeaders()->addIdHeader('Message-ID', 'TP_reply-1-0123456789abcdef@shop.example.com');
        $sent = new \Symfony\Component\Mailer\SentMessage($email, \Symfony\Component\Mailer\Envelope::create($email));
        $sent->setMessageId($original_id);

        config(['mail.driver' => 'smtp', 'mail.host' => 'email-smtp.us-east-1.amazonaws.com']);
        $this->assertSame($original_id, MailProviders::sentMessageId($sent));
        // Other SMTP servers' queue IDs aren't Message-IDs.
        config(['mail.host' => 'smtp.example.org']);
        $this->assertNull(MailProviders::sentMessageId($sent));
    }

    /**
     * A report naming the Message-ID Amazon SES gave a reply marks the reply Not delivered.
     */
    public function testReportWithTheServicesMessageIdMarksTheReply()
    {
        $conversation = $this->receiveConversation();
        $reply = $this->reply($conversation);
        SendLog::where('thread_id', $reply->id)->update(['provider_message_id' => '010001a11909e1a3-4ce1a1b5-4a65-4876-be19-000000000002-000000']);

        $this->receiveEmail($this->mailbox, file_get_contents(__DIR__.'/../Messages/delivery-report-ses-suppressed.eml'));

        $this->assertEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->fresh()->send_status);
        $report = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first()->threads()->first();
        $this->assertSame($reply->id, $report->getSendStatusData()['bounce_for_thread']);
        $this->assertTrue(SendLog::where('thread_id', $reply->id)->where('email', 'jamie.doe@customer.example.org')->where('status', SendLog::STATUS_DELIVERY_ERROR)->exists());
    }

    /**
     * @dataProvider providers
     */
    public function testSendTest($out_method, array $settings)
    {
        $this->useMailProvider($this->mailbox, $out_method, $settings);

        $response = $this->postAjax($this->agent, '/mailbox/ajax', ['action' => 'send_test', 'mailbox_id' => $this->mailbox->id, 'to' => 'admin@example.org'])->json();

        $this->assertSame('success', $response['status'], json_encode($response));
        $this->assertCount(1, $this->provider_requests);
        $this->assertStringContainsString('admin@example.org', $this->provider_requests[0]['body'].base64_decode(json_decode($this->provider_requests[0]['body'], true)['Content']['Raw']['Data'] ?? ''));
    }

    public function testSendTestShowsTheServicesError()
    {
        $this->useMailProvider($this->mailbox, Mailbox::OUT_METHOD_POSTMARK, ['postmark_token' => 'wrong']);
        $this->fakeMailProviders(function () {
            return new MockResponse(json_encode(['ErrorCode' => 10, 'Message' => 'The Server Token you provided in the X-Postmark-Server-Token request header was invalid.']), ['http_code' => 401]);
        });

        $response = $this->postAjax($this->agent, '/mailbox/ajax', ['action' => 'send_test', 'mailbox_id' => $this->mailbox->id, 'to' => 'admin@example.org'])->json();

        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('Server Token you provided', $response['msg']);
    }

    public function testOutgoingSettingsKeepSecretsEncrypted()
    {
        $this->actingAs($this->agent)->post('/mailbox/connection-settings/'.$this->mailbox->id.'/outgoing', [
            'out_method' => Mailbox::OUT_METHOD_SES,
            'out_api'    => ['ses_key' => 'AKIATEST', 'ses_secret' => 'ses-secret', 'ses_region' => 'eu-west-1'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->mailbox->refresh();
        $this->assertEquals(Mailbox::OUT_METHOD_SES, $this->mailbox->out_method);
        $this->assertNotSame('ses-secret', $this->mailbox->getMeta('out_api')['ses_secret']);
        $this->assertSame('ses-secret', MailProviders::mailboxSettings($this->mailbox)['ses_secret']);
        $this->assertTrue($this->mailbox->isOutActive());

        $page = $this->actingAs($this->agent)->get('/mailbox/connection-settings/'.$this->mailbox->id.'/outgoing')->assertOk()
            ->assertSee('Amazon SES')->assertSee('AKIATEST')->assertDontSee('ses-secret')
            ->assertSee($this->mailbox->getOutWebhookUrl(MailProviders::SES), false);

        // Saved again as shown: the secret is kept.
        $this->actingAs($this->agent)->post('/mailbox/connection-settings/'.$this->mailbox->id.'/outgoing', [
            'out_method' => Mailbox::OUT_METHOD_SES,
            'out_api'    => ['ses_key' => 'AKIATEST', 'ses_secret' => '**********', 'ses_region' => 'us-east-1'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('ses-secret', MailProviders::mailboxSettings($this->mailbox->fresh())['ses_secret']);
        $this->assertSame('us-east-1', MailProviders::mailboxSettings($this->mailbox->fresh())['ses_region']);
    }

    public function testOutgoingSettingsAreValidated()
    {
        $this->actingAs($this->agent)->post('/mailbox/connection-settings/'.$this->mailbox->id.'/outgoing', [
            'out_method' => Mailbox::OUT_METHOD_MAILGUN,
            'out_api'    => ['mailgun_domain' => 'not a domain', 'mailgun_secret' => '', 'mailgun_region' => 'asia'],
        ])->assertSessionHasErrors(['out_api.mailgun_domain', 'out_api.mailgun_secret', 'out_api.mailgun_region']);

        $this->assertEquals(Mailbox::OUT_METHOD_PHP_MAIL, $this->mailbox->fresh()->out_method);
    }

    public function testSystemEmailsThroughAService()
    {
        $this->actingAs($this->agent)->post('/app-settings/emails', ['settings' => [
            'mail_from'            => 'helpdesk@shop.example.com',
            'mail_driver'          => 'postmark',
            'mail_postmark_token'  => 'system-token',
            'mail_postmark_stream' => '',
        ]])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('postmark', Option::get('mail_driver'));
        $this->assertNotSame('system-token', Option::get('mail_postmark_token'));
        $this->actingAs($this->agent)->get('/app-settings/emails')->assertOk()->assertSee('Server Token')->assertDontSee('system-token');

        $response = $this->postAjax($this->agent, '/app-settings/ajax', ['action' => 'send_test', 'to' => 'admin@example.org'])->json();

        $this->assertSame('success', $response['status'], json_encode($response));
        $this->assertSame('system-token', $this->provider_requests[0]['headers']['x-postmark-server-token']);
    }

    public function testSystemServiceSettingsAreRequired()
    {
        $this->actingAs($this->agent)->post('/app-settings/emails', ['settings' => [
            'mail_from'   => 'helpdesk@shop.example.com',
            'mail_driver' => 'ses',
        ]])->assertSessionHasErrors(['settings.mail_ses_key', 'settings.mail_ses_secret', 'settings.mail_ses_region']);
    }
}
