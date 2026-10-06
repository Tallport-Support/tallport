<?php

namespace App\Ai;

use App\Conversation;
use Illuminate\Database\Eloquent\Model;

/**
 * The tokens of one AI call (aiassistant_usage): what a conversation cost, and what the
 * daily budget and hourly caps count.
 */
class Usage extends Model
{
    const FEATURE_TRANSLATION = 'translation';
    const FEATURE_REPLY_TRANSLATION = 'reply_translation';
    const FEATURE_SUMMARY = 'summary';
    const FEATURE_DRAFT = 'draft';
    const FEATURE_LANGUAGE = 'language';

    const UPDATED_AT = null;

    protected $table = 'aiassistant_usage';

    protected $guarded = [];

    /**
     * Record a response's tokens (laravel/ai's $response->usage), for a conversation or a mailbox.
     */
    public static function record($response, $feature, ?Conversation $conversation = null, $mailbox_id = null, $user_id = null, $items = 1)
    {
        $usage = $response->usage ?? null;
        if (!$usage) {
            return null;
        }

        return self::create([
            'mailbox_id'      => $conversation ? $conversation->mailbox_id : $mailbox_id,
            'conversation_id' => $conversation ? $conversation->id : null,
            'customer_id'     => $conversation ? $conversation->customer_id : null,
            'user_id'         => $user_id,
            'feature'         => $feature,
            'input_tokens'    => (int) $usage->inputTokens,
            'output_tokens'   => (int) $usage->outputTokens,
            'items'           => max(1, (int) $items),
        ]);
    }

    /**
     * All the tokens a conversation used.
     */
    public static function forConversation(Conversation $conversation)
    {
        return (int) self::where('conversation_id', $conversation->id)->sum(\DB::raw('input_tokens + output_tokens'));
    }

    /**
     * The tokens a mailbox used today (the daily budget).
     */
    public static function mailboxToday($mailbox_id)
    {
        return (int) self::where('mailbox_id', $mailbox_id)->where('created_at', '>=', now()->startOfDay())->sum(\DB::raw('input_tokens + output_tokens'));
    }

    /**
     * A customer's messages translated in the last hour (the hourly cap).
     */
    public static function customerTranslationsLastHour($customer_id)
    {
        return (int) self::where('customer_id', $customer_id)->where('feature', self::FEATURE_TRANSLATION)->where('created_at', '>=', now()->subHour())->sum('items');
    }
}
