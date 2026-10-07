<?php

namespace Tests\Feature;

use App\Mailbox;
use App\Option;
use App\SendLog;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\FeatureTestCase;

/**
 * MailHelper (App\Misc\Mail): the mail settings it applies before sending,
 * test emails and their failures, custom headers, and the OAuth URLs.
 * OAuth token requests are in MailHelperOauthTest, IMAP in MailHelperImapTest.
 */
class MailHelperFeatureTest extends FeatureTestCase
{
    /**
     * Every mailer FreeScout builds from now on fails to send, as an SMTP
     * server that rejects the login would.
     */
    protected function failSending($debug)
    {
        $transport = new class($debug) extends AbstractTransport {
            public $debug;

            public function __construct($debug)
            {
                parent::__construct();
                $this->debug = $debug;
            }

            public function __toString(): string
            {
                return 'failing://';
            }

            protected function doSend(SentMessage $message): void
            {
                $e = new TransportException('Failed to authenticate on SMTP server with username "agent@example.org".');
                $e->appendDebug($this->debug);

                throw $e;
            }
        };

        // An extender is kept for later managers only while none is resolved.
        $this->app->forgetInstance('mail.manager');
        $this->app->extend('mail.manager', function ($manager) use ($transport) {
            return static::captureAllMailDrivers($manager, $transport);
        });
        \MailHelper::$last_mail_config_hash = '';
    }

    protected function smtpMailbox(array $attributes = [])
    {
        $mailbox = $this->createMailbox([], ['email' => 'support@example.org']);
        $mailbox->fill(array_merge([
            'out_method'     => Mailbox::OUT_METHOD_SMTP,
            'out_server'     => 'smtp.example.org',
            'out_port'       => 587,
            'out_username'   => 'agent@example.org',
            'out_password'   => 'smtp-secret',
            'out_encryption' => Mailbox::OUT_ENCRYPTION_TLS,
        ], $attributes))->save();

        return $mailbox;
    }

    public function testSmtpMailboxSettingsAreApplied()
    {
        \MailHelper::setMailDriver($this->smtpMailbox());

        $this->assertSame('smtp.example.org', config('mail.host'));
        $this->assertEquals(587, config('mail.port'));
        $this->assertSame('', config('mail.auth_mode'));
        $this->assertSame('agent@example.org', config('mail.username'));
        $this->assertSame('smtp-secret', config('mail.password'));
        $this->assertSame('tls', config('mail.encryption'));
        $this->assertSame('support@example.org', config('mail.from')['address']);
    }

    public function testSmtpMailboxWithoutLoginSendsWithoutCredentials()
    {
        \MailHelper::setMailDriver($this->smtpMailbox(['out_username' => '', 'out_password' => '', 'out_encryption' => Mailbox::OUT_ENCRYPTION_NONE]));

        $this->assertNull(config('mail.username'));
        $this->assertNull(config('mail.password'));
        $this->assertSame('', config('mail.encryption'));
    }

    public function testSmtpMailboxWithOauthUsesTheAccessToken()
    {
        $mailbox = $this->smtpMailbox(['out_server' => \MailHelper::OAUTH_MICROSOFT_SMTP, 'out_username' => 'agent@example.org:client-id-1']);
        $mailbox->setMetaParam('oauth', [
            'provider'   => \MailHelper::OAUTH_PROVIDER_MICROSOFT,
            'a_token'    => 'access-token-1',
            'r_token'    => 'refresh-token-1',
            'issued_on'  => now()->toDateTimeString(),
            'expires_in' => 3600,
        ], true);

        \MailHelper::setMailDriver($mailbox);

        $this->assertSame('XOAUTH2', config('mail.auth_mode'));
        $this->assertSame('agent@example.org', config('mail.username'));
        $this->assertSame('access-token-1', config('mail.password'));
    }

    public function testWithoutMailboxSystemAddressIsTheSender()
    {
        Option::set('mail_from', 'system@example.org');

        \MailHelper::setMailDriver();

        $this->assertSame(['address' => 'system@example.org', 'name' => ''], config('mail.from'));
    }

    public function testSystemSmtpSettingsAreApplied()
    {
        Option::set('mail_driver', 'smtp');
        Option::set('mail_host', 'smtp.system.example.org');
        Option::set('mail_port', '465');
        Option::set('mail_username', 'system@example.org');
        Option::set('mail_password', encrypt('system-secret'));
        Option::set('mail_encryption', 'ssl');

        \MailHelper::setSystemMailDriver();

        $this->assertSame('smtp.system.example.org', config('mail.host'));
        $this->assertEquals(465, config('mail.port'));
        $this->assertSame('system@example.org', config('mail.username'));
        $this->assertSame('system-secret', config('mail.password'));
        $this->assertSame('ssl', config('mail.encryption'));
    }

    public function testSystemSmtpWithoutLoginSendsWithoutCredentials()
    {
        Option::set('mail_driver', 'smtp');
        Option::set('mail_host', 'smtp.system.example.org');
        Option::set('mail_username', '');

        \MailHelper::setSystemMailDriver();

        $this->assertNull(config('mail.username'));
        $this->assertNull(config('mail.password'));
    }

    /**
     * The error and the SMTP conversation are shown, the login (sent in
     * reply to "334") masked.
     */
    public function testFailedMailboxTestEmailReportsTheSmtpLog()
    {
        $this->failSending("> AUTH LOGIN\n< 334 VXNlcm5hbWU6\n> YWdlbnRAZXhhbXBsZS5vcmc=\n< 334 UGFzc3dvcmQ6\n> c210cC1zZWNyZXQ=\n< 535 Authentication failed\n");

        $result = \MailHelper::sendTestMail('me@example.org', $this->smtpMailbox());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Failed to authenticate', $result['msg']);
        $this->assertStringContainsString("< 334 VXNlcm5hbWU6\n> ***\n< 334 UGFzc3dvcmQ6\n> ***\n< 535 Authentication failed", $result['log']);
        $this->assertStringNotContainsString('c210cC1zZWNyZXQ=', $result['log']);
        $this->assertSame(SendLog::STATUS_SEND_ERROR, SendLog::where('email', 'me@example.org')->value('status'));
    }

    public function testFailedSystemTestEmailIsAnError()
    {
        $this->failSending("< 535 Authentication failed\n");

        $result = \MailHelper::sendTestMail('me@example.org');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Failed to authenticate', $result['msg']);
        $this->assertSame("< 535 Authentication failed\n", $result['log']);
        $this->assertSame(SendLog::STATUS_SEND_ERROR, SendLog::where('email', 'me@example.org')->value('status'));
    }

    public function testSmtpLogOnlyForTransportErrors()
    {
        $this->assertSame('', \MailHelper::getSmtpLog(new \Exception('View not found')));
    }

    /**
     * APP_CUSTOM_MAIL_HEADERS: "Name: value" pairs separated by ";".
     */
    public function testCustomMailHeadersAreAdded()
    {
        config(['app.custom_mail_headers' => 'X-Company: Example Ltd; X-Empty: ;Broken']);

        \MailHelper::sendTestMail('me@example.org', $this->createMailbox());

        $headers = $this->sentEmailsTo('me@example.org')[0]->getHeaders();
        $this->assertSame('Example Ltd', $headers->get('X-Company')->getFieldBody());
        $this->assertNull($headers->get('X-Empty'));
        $this->assertNull($headers->get('Broken'));
    }

    public function testOauthAuthorizationUrls()
    {
        $microsoft = \MailHelper::oauthGetAuthorizationUrl(\MailHelper::OAUTH_PROVIDER_MICROSOFT, ['client_id' => 'client-1', 'state' => '{"mailbox_id":5}']);
        $google = \MailHelper::oauthGetAuthorizationUrl(\MailHelper::OAUTH_PROVIDER_GOOGLE, ['client_id' => 'client-2', 'state' => 'abc']);

        [$url, $query] = explode('?', $microsoft, 2);
        parse_str($query, $args);
        $this->assertSame('https://login.microsoftonline.com/common/oauth2/v2.0/authorize', $url);
        $this->assertEquals([
            'scope'           => 'offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send',
            'response_type'   => 'code',
            'approval_prompt' => 'auto',
            'redirect_uri'    => route('mailboxes.oauth_callback'),
            'state'           => '{"mailbox_id":5}',
            'client_id'       => 'client-1',
        ], $args);

        [$url, $query] = explode('?', $google, 2);
        parse_str($query, $args);
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', $url);
        $this->assertEquals([
            'scope'         => 'https://mail.google.com/',
            'response_type' => 'code',
            'prompt'        => 'consent',
            'redirect_uri'  => route('mailboxes.oauth_callback'),
            'access_type'   => 'offline',
            'client_id'     => 'client-2',
            'state'         => 'abc',
        ], $args);
    }

    public function testOauthDisconnectRedirects()
    {
        $mailbox = $this->createMailbox();
        $back = 'https://helpdesk.example.org/mailbox/connection-settings/1/incoming';

        $microsoft = \MailHelper::oauthDisconnect(\MailHelper::OAUTH_PROVIDER_MICROSOFT, $back, $mailbox);
        $google = \MailHelper::oauthDisconnect(\MailHelper::OAUTH_PROVIDER_GOOGLE, $back, $mailbox);

        $this->assertSame('https://login.microsoftonline.com/common/oauth2/v2.0/logout?post_logout_redirect_uri='.urlencode($back), $microsoft->getTargetUrl());
        $this->assertSame($back, $google->getTargetUrl());
    }
}
