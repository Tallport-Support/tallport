<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class Test extends Mailable
{
    public $mailbox;

    /**
     * Create a new message instance.
     */
    public function __construct($mailbox = null)
    {
        $this->mailbox = $mailbox;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        \MailHelper::prepareMailable($this);
        
        $this->withSymfonyMessage(function ($message) {
            $message->getHeaders()->addTextHeader('X-FreeScout-Mail-Type', 'test.mailbox');

            return $message;
        });

        $message = $this->subject(__(':app_name Test Email', ['app_name' => \Config::get('app.name')]));
        if ($this->mailbox) {
            $message->view('emails/user/test', ['mailbox' => $this->mailbox]);
        } else {
            $message->view('emails/user/test_system');
        }

        return $message;
    }
}
