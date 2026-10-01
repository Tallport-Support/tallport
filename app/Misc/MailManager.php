<?php

namespace App\Misc;

use Illuminate\Mail\MailManager as BaseMailManager;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Laravel's mail manager with FreeScout's SMTP settings and PHP's mail()
 * function for the "mail" driver. Installed by AppServiceProvider.
 */
class MailManager extends BaseMailManager
{
    /**
     * Create an instance of the Symfony SMTP Transport driver.
     *
     * @param  array  $config
     * @return \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport
     */
    protected function createSmtpTransport(array $config)
    {
        $factory = new EsmtpTransportFactory;

        $scheme = $config['scheme'] ?? null;

        if (! $scheme) {
            // "ssl" is implicit TLS, "tls" is STARTTLS (which Symfony Mailer
            // uses whenever the server offers it).
            $encryption = $config['encryption'] ?? '';
            $scheme = ($encryption === 'ssl' || ($encryption === 'tls' && ($config['port'] ?? null) == 465))
                ? 'smtps'
                : 'smtp';
        }

        $transport = $factory->create(new Dsn(
            $scheme,
            $config['host'],
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['port'] ?? null,
            $config
        ));

        // OAuth (e.g. Microsoft 365, Gmail): the password is the access token.
        if (($config['auth_mode'] ?? null) === 'XOAUTH2') {
            $transport->setAuthenticators([new XOAuth2Authenticator()]);
        }

        // "No encryption" (approved 2026-09-30): SwiftMailer sent such mail in
        // plain text; Symfony Mailer still switches to STARTTLS when offered,
        // so a self-signed certificate must not stop sending. Never weaker
        // than the plain text before; "ssl" and "tls" keep full verification.
        if (empty($config['encryption']) && $transport->getStream() instanceof SocketStream) {
            $transport->getStream()->setStreamOptions(['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]]);
        }

        // SMTP Timeout.
        if (config('mail.smtp_timeout') && $transport->getStream() instanceof SocketStream) {
            $transport->getStream()->setTimeout((float) config('mail.smtp_timeout'));
        }

        return $this->configureSmtpTransport($transport, $config);
    }

    /**
     * PHP's mail() function, as SwiftMailer's MailTransport did.
     *
     * @return \App\Misc\PhpMailTransport
     */
    protected function createMailTransport()
    {
        return new PhpMailTransport;
    }
}
