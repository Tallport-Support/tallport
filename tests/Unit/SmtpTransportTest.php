<?php

namespace Tests\Unit;

use App\Misc\SwiftGetSmtpQueueId;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * SMTP settings of a mailbox as the mail manager applies them (Symfony
 * Mailer since Laravel 9), and the queue ID read for the send log.
 */
class SmtpTransportTest extends TestCase
{
    protected function transport(array $config)
    {
        $manager = $this->app->make('mail.manager');
        $method = new \ReflectionMethod($manager, 'createSmtpTransport');
        $method->setAccessible(true);

        return $method->invoke($manager, array_merge(['transport' => 'smtp', 'host' => 'smtp.example.org'], $config));
    }

    protected function authenticators($transport)
    {
        $property = new \ReflectionProperty($transport, 'authenticators');
        $property->setAccessible(true);

        return $property->getValue($transport);
    }

    public function testAppMailManagerSurvivesReconfiguration()
    {
        $this->assertInstanceOf(\App\Misc\MailManager::class, $this->app->make('mail.manager'));

        // As MailHelper::reapplyMailConfig() does when mailbox settings change.
        $this->app->forgetInstance('mail.manager');

        $this->assertInstanceOf(\App\Misc\MailManager::class, $this->app->make('mail.manager'));
    }

    public function testSslIsImplicitTls()
    {
        $this->assertTrue($this->transport(['port' => 465, 'encryption' => 'ssl'])->getStream()->isTLS());
    }

    public function testTlsIsStarttls()
    {
        $this->assertFalse($this->transport(['port' => 587, 'encryption' => 'tls'])->getStream()->isTLS());
    }

    public function testOauthUsesXoauth2()
    {
        $authenticators = $this->authenticators($this->transport(['port' => 587, 'encryption' => 'tls', 'auth_mode' => 'XOAUTH2']));

        $this->assertCount(1, $authenticators);
        $this->assertInstanceOf(XOAuth2Authenticator::class, $authenticators[0]);
    }

    public function testPasswordAuthKeepsDefaultAuthenticators()
    {
        $this->assertGreaterThan(1, count($this->authenticators($this->transport(['port' => 587, 'encryption' => 'tls']))));
    }

    public function testNoEncryptionAcceptsSelfSignedStarttls()
    {
        $options = $this->transport(['port' => 25, 'encryption' => ''])->getStream()->getStreamOptions();

        $this->assertFalse($options['ssl']['verify_peer']);
        $this->assertTrue($options['ssl']['allow_self_signed']);
    }

    public function testEncryptedConnectionsVerifyCertificates()
    {
        foreach ([['port' => 465, 'encryption' => 'ssl'], ['port' => 587, 'encryption' => 'tls']] as $config) {
            $options = $this->transport($config)->getStream()->getStreamOptions();
            $this->assertNotFalse($options['ssl']['verify_peer'] ?? true, json_encode($config));
        }
    }

    public function testSmtpTimeout()
    {
        config(['mail.smtp_timeout' => 17]);

        $this->assertEquals(17, $this->transport(['port' => 587])->getStream()->getTimeout());
    }

    /**
     * Against tests/Support/fake-smtp-server.php, which closes connections idle for 0.3 seconds.
     */
    public function testConnectionTheServerClosedIsOpenedAgain()
    {
        $server = proc_open([PHP_BINARY, base_path('tests/Support/fake-smtp-server.php'), '0.3'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);
        $email = (new \Symfony\Component\Mime\Email())->from('mailbox@example.org')->to('agent@example.org')->subject('Notification')->text('Hello');

        try {
            $transport = $this->transport(['host' => '127.0.0.1', 'port' => $port, 'encryption' => '']);
            $transport->send($email);
            usleep(500000);
            $transport->send($email);
            $transport->stop();
            $received = [fgets($pipes[1]), fgets($pipes[1])];
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        $this->assertSame(["message\n", "message\n"], $received);
    }

    public function testQueueIdFromServerReply()
    {
        $sent = new SentMessage(new RawMessage('x'), new Envelope(new Address('a@example.org'), [new Address('b@example.org')]));
        $sent->appendDebug("> DATA\r\n< 354 End data with <CR><LF>.<CR><LF>\r\n< 250 2.0.0 Ok: queued as 4ZxQ1B2c3D\r\n");

        $this->assertSame('4ZxQ1B2c3D', SwiftGetSmtpQueueId::fromSentMessage($sent));
        $this->assertNull(SwiftGetSmtpQueueId::fromSentMessage(null));
    }
}
