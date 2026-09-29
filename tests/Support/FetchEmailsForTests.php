<?php

namespace Tests\Support;

use App\Console\Commands\FetchEmails;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The fetch-emails command, usable without an IMAP server: messages are
 * handed to processMessage() directly instead of being fetched.
 */
class FetchEmailsForTests extends FetchEmails
{
    /**
     * @var BufferedOutput
     */
    public $buffer;

    public function __construct()
    {
        parent::__construct();

        // Normally set by run(); processMessage() writes progress to it.
        $this->buffer = new BufferedOutput();
        $this->output = new OutputStyle(new ArrayInput([]), $this->buffer);
    }

    /**
     * Marking a message as seen talks to the IMAP server, which parsed
     * messages don't have.
     */
    public function setSeen($message, $mailbox)
    {
    }
}
