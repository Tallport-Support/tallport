<?php

namespace App\Console\Commands;

use App\Email;
use App\Incoming\LegacyImapMessage;
use App\Mailbox;
use App\Subscription;
use Illuminate\Console\Command;

class Receive extends Command
{
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

            return 1;
        }
        if (!str_contains($raw, "\r\n")) {
            $raw = str_replace("\n", "\r\n", $raw);
        }

        // Message::fromString() reads the options set up by the client manager.
        new \App\LegacyImap\ClientManager(config('imap'));
        $message = new LegacyImapMessage(\App\LegacyImap\Message::fromString($raw));

        $mailboxes = Mailbox::get();
        $mailbox = $this->findMailbox($message, $mailboxes);
        if (!$mailbox) {
            $this->error($this->option('mailbox')
                ? 'Mailbox not found: '.$this->option('mailbox')
                : 'No mailbox among the recipients; pass --mailbox');

            return 1;
        }

        $fetch = new FetchEmails();
        $fetch->setLaravel($this->laravel);
        $fetch->setOutput($this->output);
        $fetch->mailbox = $mailbox;
        $fetch->extra_import = [];

        $this->line('['.date('Y-m-d H:i:s').'] Mailbox: '.$mailbox->name.'; '.$message->subject());
        $fetch->processMessage($message, $message->messageId(), $mailbox, $mailboxes);

        // Also into other mailboxes among the recipients.
        foreach ($fetch->extra_import as $extra_import) {
            $fetch->processMessage($extra_import['message'], $extra_import['message_id'], $extra_import['mailbox'], [], true);
        }

        // Commands don't run the terminate handler that sends notifications.
        Subscription::processEvents();

        return 0;
    }

    /**
     * The mailbox from --mailbox, or the first mailbox among the recipients.
     */
    protected function findMailbox(LegacyImapMessage $message, $mailboxes)
    {
        $option = $this->option('mailbox');
        if ($option) {
            return $mailboxes->first(function ($mailbox) use ($option) {
                return (string) $mailbox->id === $option || Email::sanitizeEmail($mailbox->email) === Email::sanitizeEmail($option);
            });
        }

        $recipients = [];
        foreach (array_merge($message->to(), $message->cc(), $message->bcc()) as $address) {
            $recipients[] = Email::sanitizeEmail($address->mail);
        }

        foreach ($recipients as $recipient) {
            $mailbox = $mailboxes->first(function ($mailbox) use ($recipient) {
                return Email::sanitizeEmail($mailbox->email) === $recipient;
            });
            if ($mailbox) {
                return $mailbox;
            }
        }

        return null;
    }
}
