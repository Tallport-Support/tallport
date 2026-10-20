<?php

namespace App\Jobs;

use App\Mail\WebChatCode;
use App\WebChat\Visitor;
use App\WebChat\WebChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a website chat visitor the code that confirms their address (App\WebChat\EmailCode),
 * from the mailbox, in the visitor's language.
 */
class SendWebChatCode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    public $visitor_id;

    public $email;

    public $code;

    public $locale;

    public function __construct($visitor_id, $email, $code, $locale)
    {
        $this->onConnection(\Helper::queueConnection('emails'));
        $this->visitor_id = $visitor_id;
        $this->email = $email;
        $this->code = $code;
        $this->locale = $locale;
    }

    public function handle()
    {
        $visitor = Visitor::find($this->visitor_id);
        // Only while it's still the address waiting for this code.
        if (!$visitor || $visitor->pending_email !== $this->email || !\App\WebChat\EmailCode::waiting($visitor) || !$visitor->mailbox) {
            return;
        }
        $mailbox = $visitor->mailbox;
        \App\Misc\Mail::setMailDriver($mailbox);
        app()->setLocale($this->locale);
        Mail::to($this->email)->send((new WebChatCode($this->code, WebChat::teamName($mailbox)))->from($mailbox->email, $mailbox->name));
    }
}
