<?php

namespace App\Telegram;

use App\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * Each try at sending a reply to a customer's Telegram chat (SendReplyToTelegram):
 * Manage » Logs » Outgoing Telegram.
 */
class TelegramSend extends Model
{
    const STATUS_SENT = 1;
    const STATUS_FAILED = 2;
    // Failed, the job tries again later.
    const STATUS_RETRYING = 3;

    protected $table = 'telegram_sends';

    protected $guarded = ['id'];

    protected $casts = ['message_ids' => 'array'];

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }

    public function conversation()
    {
        return $this->belongsTo('App\Conversation');
    }

    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }

    /**
     * Record a try; never in the way of sending.
     */
    public static function record(\App\Thread $thread, $attempt, $status, array $message_ids = [], $error = null)
    {
        $conversation = $thread->conversation;
        try {
            self::create([
                'mailbox_id'      => $conversation->mailbox_id,
                'conversation_id' => $conversation->id,
                'thread_id'       => $thread->id,
                'customer_id'     => $conversation->customer_id,
                'attempt'         => min(255, max(1, (int) $attempt)),
                'status'          => $status,
                'message_ids'     => array_values($message_ids) ?: null,
                'files'           => $thread->attachments->count(),
                'error'           => $error !== null ? mb_substr((string) $error, 0, 1000) : null,
            ]);
        } catch (\Exception $e) {
            \Helper::logException($e, 'Telegram send log: ');
        }
    }

    /**
     * [name, badge tone] of the status.
     */
    public function statusName()
    {
        switch ($this->status) {
            case self::STATUS_SENT:
                return [__('Succeeded'), 'success'];
            case self::STATUS_RETRYING:
                return [__('Failed, Will Retry'), 'warning'];
            default:
                return [__('Failed'), 'danger'];
        }
    }

    /**
     * The customer's Telegram username (from their profile), or ''.
     */
    public static function username(?Customer $customer)
    {
        if (!$customer) {
            return '';
        }
        foreach ($customer->getSocialProfiles() as $profile) {
            if (($profile['type'] ?? null) == Customer::SOCIAL_TYPE_TELEGRAM && (string) ($profile['value'] ?? '') !== '') {
                return (string) $profile['value'];
            }
        }

        return '';
    }
}
