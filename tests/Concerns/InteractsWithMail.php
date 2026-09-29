<?php

namespace Tests\Concerns;

use App\Mailbox;
use Tests\Support\FetchEmailsForTests;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Email in and out without mail servers.
 *
 * Incoming: raw RFC 822 messages are parsed by Webklex and processed by the
 * same code freescout:fetch-emails runs for each fetched message.
 *
 * Outgoing: FreeScout picks the mail driver per mailbox and rebuilds the
 * mailer when the mail config changes (MailHelper::reapplyMailConfig), which
 * replaces Mail::fake(). Instead, every rebuilt mailer is switched to
 * Laravel's array transport, which keeps the real Swift messages, headers
 * included.
 */
trait InteractsWithMail
{
    /**
     * One transport shared by every mailer FreeScout builds during a test,
     * so messages survive the mailer being rebuilt.
     *
     * @var \Illuminate\Mail\Transport\ArrayTransport
     */
    protected $captured_mail;

    protected function captureSentMail()
    {
        \MailHelper::$last_mail_config_hash = '';
        \MailHelper::$smtp_queue_id_plugin_registered = false;

        $this->captured_mail = new \Illuminate\Mail\Transport\ArrayTransport();
        $transport = $this->captured_mail;

        // Container extenders survive re-registering the mail provider, so every
        // transport manager FreeScout creates hands out the shared transport.
        $this->app->extend('swift.transport', function ($manager) use ($transport) {
            $manager->extend('array', function () use ($transport) {
                return $transport;
            });

            return $manager;
        });

        $use_array_transport = function () {
            \Config::set('mail.driver', 'array');
            \App::forgetInstance('mailer');
            \App::forgetInstance('swift.mailer');
            \App::forgetInstance('swift.transport');
            (new \Illuminate\Mail\MailServiceProvider(app()))->register();
            \Mail::swap(app('mailer'));
        };
        $use_array_transport();
        \Eventy::addAction('mail.reapply_mail_config', $use_array_transport);
    }

    /**
     * Emails sent so far.
     *
     * @return \Swift_Message[]
     */
    protected function sentEmails()
    {
        if (!$this->captured_mail) {
            $this->fail('Sent mail is not being captured: call captureSentMail() in setUp().');
        }

        return array_values($this->captured_mail->messages()->all());
    }

    /**
     * Emails sent to the given address (To, Cc or Bcc).
     *
     * @return \Swift_Message[]
     */
    protected function sentEmailsTo($email)
    {
        return array_values(array_filter($this->sentEmails(), function (\Swift_Message $message) use ($email) {
            $recipients = array_merge(
                array_keys($message->getTo() ?: []),
                array_keys($message->getCc() ?: []),
                array_keys($message->getBcc() ?: [])
            );

            return in_array(strtolower($email), array_map('strtolower', $recipients));
        }));
    }

    /**
     * Receive an email into a mailbox, as freescout:fetch-emails would after
     * fetching it, including sending the notifications it triggers.
     *
     * Pass all mailboxes being fetched to have an email addressed to several
     * of them imported into each, as the command does.
     *
     * @return string The command's output.
     */
    protected function receiveEmail(Mailbox $mailbox, $raw_message, array $all_mailboxes = [])
    {
        // Message::fromString() reads the Webklex options set up by the client manager.
        new ClientManager(config('imap'));
        $message = Message::fromString($raw_message);

        $command = new FetchEmailsForTests();
        $command->mailbox = $mailbox;
        $command->processMessage($message, (string)$message->getMessageId(), $mailbox, $all_mailboxes);
        foreach ($command->extra_import as $extra_import) {
            $command->processMessage($extra_import['message'], $extra_import['message_id'], $extra_import['mailbox'], [], true);
        }

        \App\Subscription::processEvents();

        return $command->buffer->fetch();
    }

    /**
     * Build a raw email. Options: from, to, cc, subject, message_id,
     * in_reply_to, references, body, html (bool), date, headers (array).
     */
    protected function makeEmail(array $options)
    {
        $options = array_merge([
            'subject'    => 'Question about my order',
            'message_id' => 'msg-'.bin2hex(random_bytes(8)).'@customer.example.org',
            'body'       => "Hello,\n\nWhere is my order?\n\nThanks",
            'html'       => false,
            'date'       => date('r'),
            'headers'    => [],
        ], $options);

        $headers = [
            'From'       => $options['from'],
            'To'         => $options['to'],
            'Subject'    => $options['subject'],
            'Date'       => $options['date'],
            'Message-ID' => '<'.$options['message_id'].'>',
        ];
        if (!empty($options['cc'])) {
            $headers['Cc'] = $options['cc'];
        }
        if (!empty($options['in_reply_to'])) {
            $headers['In-Reply-To'] = '<'.$options['in_reply_to'].'>';
        }
        if (!empty($options['references'])) {
            $headers['References'] = implode(' ', array_map(function ($id) {
                return '<'.$id.'>';
            }, (array)$options['references']));
        }
        $headers['MIME-Version'] = '1.0';
        $headers['Content-Type'] = ($options['html'] ? 'text/html' : 'text/plain').'; charset=UTF-8';
        $headers['Content-Transfer-Encoding'] = '8bit';
        $headers = array_merge($headers, $options['headers']);

        $raw = '';
        foreach ($headers as $name => $value) {
            $raw .= $name.': '.$value."\r\n";
        }

        return $raw."\r\n".str_replace("\n", "\r\n", str_replace("\r\n", "\n", $options['body']))."\r\n";
    }
}
