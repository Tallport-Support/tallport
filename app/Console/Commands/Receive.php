<?php

namespace App\Console\Commands;

use App\Email;
use App\Incoming\IncomingMessage;
use App\Incoming\Parser;
use App\Mailbox;
use App\Subscription;
use Illuminate\Console\Command;

class Receive extends Command
{
    /**
     * Exit codes for mail servers (sysexits.h): 75 makes them try again
     * later, the others bounce the email.
     */
    const EX_NOINPUT = 66;
    const EX_NOUSER = 67;
    const EX_TEMPFAIL = 75;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:receive
        {file? : An email (.eml) file; read from standard input if omitted}
        {--mailbox= : Mailbox ID or email address; found from the recipients if omitted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Receive an email as if it had been fetched (from a file, or piped in by a mail server)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $file = $this->argument('file');
        $raw = $file ? @file_get_contents($file) : stream_get_contents(STDIN);
        if (!$raw) {
            $this->error($file ? 'Could not read '.$file : 'No email on standard input');

            return self::EX_NOINPUT;
        }
        if (!str_contains($raw, "\r\n")) {
            $raw = str_replace("\n", "\r\n", $raw);
        }

        try {
            return $this->saveEmail($raw);
        } catch (\Throwable $e) {
            \Helper::logException($e, '[tallport:receive]');
            $this->error('Could not receive the email: '.$e->getMessage());

            return self::EX_TEMPFAIL;
        }
    }

    /**
     * Save the email into its mailbox (and other mailboxes among the recipients).
     *
     * @return int
     */
    protected function saveEmail($raw)
    {
        $message = Parser::parse($raw);

        $mailboxes = Mailbox::get();
        $mailbox = $this->findMailbox($message, $mailboxes);
        if (!$mailbox) {
            $this->error($this->option('mailbox')
                ? 'Mailbox not found: '.$this->option('mailbox')
                : 'No mailbox among the recipients; pass --mailbox');

            return self::EX_NOUSER;
        }

        // FetchEmails imports an email sent to several mailboxes into the
        // others only if they receive email, which it knows from their IMAP
        // settings: mailboxes receiving through a pipe may have none.
        \Eventy::addFilter('mailbox.in_active', function ($in_active) {
            return true;
        }, 20, 1);

        $fetch = new FetchEmails();
        $fetch->setLaravel($this->laravel);
        $fetch->setOutput($this->output);
        $fetch->mailbox = $mailbox;
        $fetch->extra_import = [];

        $this->line('['.date('Y-m-d H:i:s').'] Mailbox: '.$mailbox->name.'; '.$message->subject());
        $fetch->processMessage($message, $message->messageId(), $mailbox, $mailboxes);
        if ($fetch->last_message_failed) {
            // Already logged. Receiving it again is safe: an email received
            // before is recognised by its Message-ID.
            return self::EX_TEMPFAIL;
        }

        // Also into other mailboxes among the recipients.
        foreach ($fetch->extra_import as $extra_import) {
            $fetch->processMessage($extra_import['message'], $extra_import['message_id'], $extra_import['mailbox'], [], true);
        }

        // Commands don't run the terminate handler that sends notifications.
        Subscription::processEvents();

        return 0;
    }

    /**
     * The mailbox from --mailbox, or the first mailbox among the recipients
     * (by its address or one of its aliases).
     */
    protected function findMailbox(IncomingMessage $message, $mailboxes)
    {
        $option = $this->option('mailbox');
        if ($option) {
            return $mailboxes->first(function ($mailbox) use ($option) {
                return (string) $mailbox->id === $option || self::hasAddress($mailbox, $option);
            });
        }

        foreach (array_merge($message->to(), $message->cc(), $message->bcc()) as $address) {
            $mailbox = $mailboxes->first(function ($mailbox) use ($address) {
                return self::hasAddress($mailbox, $address->mail);
            });
            if ($mailbox) {
                return $mailbox;
            }
        }

        return null;
    }

    protected static function hasAddress(Mailbox $mailbox, $email)
    {
        $email = Email::sanitizeEmail($email);

        return $email && in_array($email, array_map([Email::class, 'sanitizeEmail'], $mailbox->getEmails()), true);
    }
}
