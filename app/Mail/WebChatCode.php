<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The code that confirms a website chat visitor's address (App\WebChat\EmailCode): the code and the
 * team's name, nothing the visitor typed.
 */
class WebChatCode extends Mailable
{
    public $code;

    public $team;

    public function __construct($code, $team)
    {
        $this->code = $code;
        $this->team = $team;
    }

    public function build()
    {
        \MailHelper::prepareMailable($this);

        return $this->subject(__(':code is your code for :name', ['code' => $this->code, 'name' => $this->team]))
            ->view('emails/customer/web_chat_code')
            ->text('emails/customer/web_chat_code_text');
    }
}
