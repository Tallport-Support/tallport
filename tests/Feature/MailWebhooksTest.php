<?php

namespace Tests\Feature;

use App\Conversation;
use App\Email;
use App\Mailbox;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMailProviders;
use Tests\FeatureTestCase;

/**
 * Sending services' webhooks for a mailbox's delivery events: accepted only
 * as each service signs them (and with the mailbox's secret in the URL),
 * recorded as report emails are (the address flagged, the reply marked Not
 * delivered), and once when the report also comes as an email.
 */
class MailWebhooksTest extends FeatureTestCase
{
    use FakesMailProviders;

    const SES_ID = '010001a2b3c4d5e6-11111111-2222-3333-4444-555555555555-000000';
    const POSTMARK_ID = 'b7bc2f4a-e38e-4336-af7d-e6c392c2f817';
    const RESEND_ID = '49a3999c-0ce1-4ea6-ab68-afcd6dc2e794';
    const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-0000000000000000000000.pem';

    protected $agent;
    protected $mailbox;
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent], ['email' => 'support@shop.example.com']);
        $this->fakeMailProviders();
        Http::preventStrayRequests();
    }

    /**
     * A reply to Casey sent through the mailbox's service.
     */
    protected function sentReply($out_method, array $settings)
    {
        $this->useMailProvider($this->mailbox, $out_method, $settings);
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $this->mailbox->email,
            'subject' => 'Question about my order',
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id, 'body' => '<p>Our answer</p>',
        ]);

        return $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
    }

    protected function postWebhook($provider, $body, array $server = [], $token = null)
    {
        $url = $token === null ? $this->mailbox->getOutWebhookUrl($provider) : route('mail.webhook', ['provider' => $provider, 'mailbox_id' => $this->mailbox->id, 'token' => $token]);

        return $this->call('POST', $url, [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $server), $body);
    }

    protected function mailgunBounce($message_id, $key)
    {
        $timestamp = (string) time();

        return json_encode([
            'signature'  => ['timestamp' => $timestamp, 'token' => 'f3c1b2', 'signature' => hash_hmac('sha256', $timestamp.'f3c1b2', $key)],
            'event-data' => [
                'id'              => 'G9Bn5sl1TC6nu79C8C0bwg',
                'event'           => 'failed',
                'severity'        => 'permanent',
                'timestamp'       => time() + 0.25,
                'recipient'       => 'casey@customer.example.org',
                'delivery-status' => ['code' => 550, 'enhanced-code' => '5.1.1', 'message' => '5.1.1 The email account that you tried to reach does not exist.', 'description' => ''],
                'message'         => ['headers' => ['message-id' => $message_id, 'subject' => 'Re: Question about my order']],
            ],
        ]);
    }

    public function testMailgunBounceMarksTheReply()
    {
        $reply = $this->sentReply(Mailbox::OUT_METHOD_MAILGUN, ['mailgun_domain' => 'mg.example.net', 'mailgun_secret' => 'key-test', 'mailgun_region' => 'us', 'mailgun_webhook_key' => 'signing-key']);

        $this->postWebhook('mailgun', $this->mailgunBounce($reply->getMessageId(), 'signing-key'))->assertOk();

        $reply->refresh();
        $this->assertEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->send_status);
        $this->assertSame('unknown_address', $reply->getSendStatusData()['delivery_problem']['reason']);
        $this->assertSame('bounce', Email::where('email', 'casey@customer.example.org')->first()->delivery_problem['kind']);
        $this->assertSame(1, SendLog::where('thread_id', $reply->id)->where('status', SendLog::STATUS_DELIVERY_ERROR)->count());
        $this->actingAs($this->agent)->followingRedirects()->get($this->conversation->url())->assertOk()
            ->assertSee('Message not sent to customer')->assertSee('Not delivered to')->assertSee('The address does not exist.');

        // Sent again: recorded once.
        $this->postWebhook('mailgun', $this->mailgunBounce($reply->getMessageId(), 'signing-key'))->assertOk();
        $this->assertSame(1, SendLog::where('thread_id', $reply->id)->where('status', SendLog::STATUS_DELIVERY_ERROR)->count());
    }

    public function testUnsignedOrUnauthorizedRequestsAreRejected()
    {
        $reply = $this->sentReply(Mailbox::OUT_METHOD_MAILGUN, ['mailgun_domain' => 'mg.example.net', 'mailgun_secret' => 'key-test', 'mailgun_region' => 'us', 'mailgun_webhook_key' => 'signing-key']);

        $this->postWebhook('mailgun', $this->mailgunBounce($reply->getMessageId(), 'someone-elses-key'))->assertStatus(406);
        $this->postWebhook('mailgun', $this->mailgunBounce($reply->getMessageId(), 'signing-key'), [], 'wrong-token')->assertStatus(403);
        $this->call('POST', route('mail.webhook', ['provider' => 'mailgun', 'mailbox_id' => 999999, 'token' => 'x']), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(404);

        $this->assertNotEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->fresh()->send_status);
        $this->assertNull(Email::where('email', 'casey@customer.example.org')->first()->delivery_problem);
    }

    public function testPostmarkComplaintFlagsTheAddress()
    {
        $reply = $this->sentReply(Mailbox::OUT_METHOD_POSTMARK, ['postmark_token' => 'pm-token']);
        $complaint = json_encode([
            'RecordType' => 'SpamComplaint', 'ID' => 42, 'Type' => 'SpamComplaint', 'TypeCode' => 512, 'MessageID' => self::POSTMARK_ID,
            'Email' => 'casey@customer.example.org', 'BouncedAt' => date('Y-m-d\TH:i:sP'), 'Subject' => 'Re: Question about my order',
        ]);

        $this->postWebhook('postmark', $complaint, [], 'wrong-token')->assertStatus(403);
        $this->postWebhook('postmark', $complaint)->assertOk();

        $this->assertSame('complaint', Email::where('email', 'casey@customer.example.org')->first()->delivery_problem['kind']);
        $this->assertTrue(SendLog::where('thread_id', $reply->id)->where('status', SendLog::STATUS_COMPLAINED)->exists());
        $this->assertNotEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->fresh()->send_status, 'A complaint means it was delivered.');
    }

    protected function resendRequest($secret, $type = 'email.bounced')
    {
        $body = json_encode([
            'type'       => $type,
            'created_at' => '2026-10-08T12:00:00.000000+00:00',
            'data'       => [
                'created_at' => '2026-10-08T11:59:00.000000+00:00', 'email_id' => self::RESEND_ID, 'from' => 'support@shop.example.com',
                'to' => ['casey@customer.example.org'], 'subject' => 'Re: Question about my order',
                'bounce' => ['message' => 'The recipient\'s mailbox is full.', 'subType' => 'MailboxFull', 'type' => 'Permanent'],
            ],
        ]);
        $id = 'msg_2mN8m';
        $timestamp = time();
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, base64_decode(substr($secret, 6)), true));

        return [$body, ['HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => (string) $timestamp, 'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature]];
    }

    public function testResendBounceIsAcceptedOnlyWithItsSignature()
    {
        $secret = 'whsec_'.base64_encode('resend-signing-secret');
        $reply = $this->sentReply(Mailbox::OUT_METHOD_RESEND, ['resend_key' => 're_test']);

        // No signing secret set yet.
        [$body, $headers] = $this->resendRequest($secret);
        $this->postWebhook('resend', $body, $headers)->assertStatus(403);

        \App\Misc\MailProviders::saveMailboxSettings($this->mailbox, ['resend_webhook_secret' => $secret]);
        $this->mailbox->save();
        [$body, $headers] = $this->resendRequest('whsec_'.base64_encode('another-secret'));
        $this->postWebhook('resend', $body, $headers)->assertStatus(406);
        $this->assertNotEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->fresh()->send_status);

        [$body, $headers] = $this->resendRequest($secret);
        $this->postWebhook('resend', $body, $headers)->assertOk();
        $this->assertEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->fresh()->send_status);
        $this->assertSame('mailbox_full', $reply->fresh()->getSendStatusData()['delivery_problem']['reason']);
    }

    /**
     * An Amazon SNS message signed with a made-up certificate served at $cert_url.
     */
    protected function snsMessage(array $message, $cert_url = self::CERT_URL)
    {
        static $key = null, $pem = null;
        if (!$key) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key), null, $key, 1);
            openssl_x509_export($cert, $pem);
        }
        Http::fake([
            self::CERT_URL                                       => Http::response($pem),
            'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription*' => Http::response('<ConfirmSubscriptionResponse/>'),
        ]);

        $message = array_merge([
            'MessageId'        => 'd5e8b8a6-0000-4000-8000-000000000001',
            'TopicArn'         => 'arn:aws:sns:us-east-1:123456789012:ses-events',
            'Timestamp'        => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '2',
            'SigningCertURL'   => $cert_url,
        ], $message);
        $fields = $message['Type'] == 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $string = '';
        foreach ($fields as $field) {
            if (isset($message[$field])) {
                $string .= $field."\n".$message[$field]."\n";
            }
        }
        openssl_sign($string, $signature, $key, OPENSSL_ALGO_SHA256);
        $message['Signature'] = base64_encode($signature);

        return json_encode($message);
    }

    protected function sesBounce($recipient, $ses_id = self::SES_ID)
    {
        return $this->snsMessage(['Type' => 'Notification', 'Message' => json_encode([
            'notificationType' => 'Bounce',
            'bounce'           => [
                'bounceType' => 'Permanent', 'bounceSubType' => 'OnAccountSuppressionList',
                'bouncedRecipients' => [['emailAddress' => $recipient, 'action' => 'failed', 'status' => '5.1.1', 'diagnosticCode' => 'Amazon SES has suppressed sending to this address.']],
            ],
            'mail' => ['messageId' => $ses_id, 'commonHeaders' => ['subject' => 'Re: Question about my order']],
        ])]);
    }

    protected function postSns($body)
    {
        return $this->postWebhook('ses', $body, ['CONTENT_TYPE' => 'text/plain; charset=UTF-8']);
    }

    public function testSesSubscriptionAndNotificationsThroughSns()
    {
        $reply = $this->sentReply(Mailbox::OUT_METHOD_SES, ['ses_key' => 'AKIATEST', 'ses_secret' => 'ses-secret', 'ses_region' => 'us-east-1']);

        $subscribe_url = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=arn:aws:sns:us-east-1:123456789012:ses-events&Token=abc';
        $this->postSns($this->snsMessage(['Type' => 'SubscriptionConfirmation', 'Message' => 'You have chosen to subscribe.', 'Token' => 'abc', 'SubscribeURL' => $subscribe_url]))->assertOk();
        Http::assertSent(fn ($request) => $request->url() == $subscribe_url);

        // Signed, but not by Amazon SNS's certificate; and changed after signing.
        $this->postSns($this->snsMessage(['Type' => 'Notification', 'Message' => '{}'], 'https://sns.example.org/cert.pem'))->assertStatus(406);
        $tampered = json_decode($this->sesBounce('casey@customer.example.org'), true);
        $tampered['Message'] = str_replace('casey@', 'robin@', $tampered['Message']);
        $this->postSns(json_encode($tampered))->assertStatus(406);
        $this->assertNotEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->fresh()->send_status);

        $this->postSns($this->sesBounce('casey@customer.example.org'))->assertOk();
        $this->assertEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->fresh()->send_status);
        $this->assertSame('suppressed', Email::where('email', 'casey@customer.example.org')->first()->delivery_problem['kind']);
    }

    /**
     * Amazon SES reports by SNS and by email: the second one is recorded and shown once.
     */
    public function testReportEmailAfterTheWebhookIsShownOnce()
    {
        $ses_id = '010001a11909e1a3-4ce1a1b5-4a65-4876-be19-000000000002-000000';
        $reply = $this->sentReply(Mailbox::OUT_METHOD_SES, ['ses_key' => 'AKIATEST', 'ses_secret' => 'ses-secret', 'ses_region' => 'us-east-1']);
        SendLog::where('thread_id', $reply->id)->update(['provider_message_id' => $ses_id]);

        $this->postSns($this->sesBounce('jamie.doe@customer.example.org', $ses_id))->assertOk();
        $output = $this->receiveEmail($this->mailbox, file_get_contents(__DIR__.'/../Messages/delivery-report-ses-suppressed.eml'));

        $this->assertStringContainsString('Delivery report recorded already', $output);
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count(), 'No conversation for the report email.');
        $this->assertSame(1, SendLog::where('thread_id', $reply->id)->where('email', 'jamie.doe@customer.example.org')->count());
    }

    public function testWebhookAfterTheReportEmailIsIgnored()
    {
        $ses_id = '010001a11909e1a3-4ce1a1b5-4a65-4876-be19-000000000002-000000';
        $reply = $this->sentReply(Mailbox::OUT_METHOD_SES, ['ses_key' => 'AKIATEST', 'ses_secret' => 'ses-secret', 'ses_region' => 'us-east-1']);
        SendLog::where('thread_id', $reply->id)->update(['provider_message_id' => $ses_id]);

        $this->receiveEmail($this->mailbox, file_get_contents(__DIR__.'/../Messages/delivery-report-ses-suppressed.eml'));
        $report_thread_id = $reply->fresh()->getSendStatusData()['bounced_by_thread'];
        $this->postSns($this->sesBounce('jamie.doe@customer.example.org', $ses_id))->assertOk();

        $this->assertSame(1, SendLog::where('thread_id', $reply->id)->where('email', 'jamie.doe@customer.example.org')->count());
        $this->assertArrayNotHasKey('delivery_problem', $reply->fresh()->getSendStatusData());
        $this->assertSame($report_thread_id, $reply->fresh()->getSendStatusData()['bounced_by_thread']);
    }

    /**
     * Events about email Tallport didn't send are ignored.
     */
    public function testEventForAnotherEmailIsIgnored()
    {
        $this->sentReply(Mailbox::OUT_METHOD_POSTMARK, ['postmark_token' => 'pm-token']);

        $this->postWebhook('postmark', json_encode([
            'RecordType' => 'Bounce', 'Type' => 'HardBounce', 'MessageID' => '00000000-0000-0000-0000-000000000000',
            'Email' => 'casey@customer.example.org', 'BouncedAt' => date('Y-m-d\TH:i:sP'), 'Description' => 'Unknown user',
        ]))->assertOk();

        $this->assertNull(Email::where('email', 'casey@customer.example.org')->first()->delivery_problem);
    }
}
