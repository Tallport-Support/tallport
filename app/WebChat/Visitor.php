<?php

namespace App\WebChat;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use Illuminate\Database\Eloquent\Model;

/**
 * A website chat visitor: a browser on a website, known by the token its chat window keeps (only
 * its SHA-256 is stored). A customer once they write.
 */
class Visitor extends Model
{
    protected $table = 'web_chat_visitors';

    protected $guarded = ['id'];

    protected $casts = [
        'last_seen_at'    => 'datetime',
        'code_expires_at' => 'datetime',
    ];

    /**
     * A new visitor, and the token their window keeps: nothing is stored until they write (or the
     * website signs them in), so page views and polling write nothing (App\WebChat\Spam).
     */
    public static function start(Mailbox $mailbox)
    {
        $token = Spam::newToken($mailbox);

        return [new self(['mailbox_id' => $mailbox->id, 'token' => hash('sha256', $token), 'last_seen_at' => now()]), $token];
    }

    /**
     * The visitor with this token: stored, or new (not stored yet) when the token is one the widget
     * signed.
     */
    public static function findByToken($token, Mailbox $mailbox)
    {
        $token = (string) $token;
        if (strlen($token) < 32) {
            return null;
        }
        $visitor = self::where('token', hash('sha256', $token))->where('mailbox_id', $mailbox->id)->first();
        if (!$visitor && Spam::validToken($token, $mailbox)) {
            $visitor = new self(['mailbox_id' => $mailbox->id, 'token' => hash('sha256', $token), 'last_seen_at' => now()]);
        }

        return $visitor;
    }

    public function mailbox()
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Who they are as a customer, made with their first message.
     */
    public function makeCustomer()
    {
        if ($this->customer) {
            return $this->customer;
        }
        $customer = Customer::createWithoutEmail(['channel' => WebChat::CHANNEL, 'channel_id' => 'v'.$this->id]);
        $this->customer_id = $customer->id;
        $this->save();

        return $this->setRelation('customer', $customer)->customer;
    }

    /**
     * Their conversation while it can continue (the mailbox's Reopen Window), whichever channel it
     * is on now; else none, and their next message starts one.
     */
    public function currentConversation()
    {
        $conversation = $this->conversation;
        if (!$conversation || $conversation->mailbox_id != $this->mailbox_id || $conversation->customer_id != $this->customer_id
            || $conversation->state == Conversation::STATE_DELETED || !\App\Misc\ChatConversations::canContinue($conversation, $this->mailbox)) {
            return null;
        }

        return $conversation;
    }
}
