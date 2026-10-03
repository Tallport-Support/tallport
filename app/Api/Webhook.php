<?php

namespace App\Api;

use App\Conversation;
use Illuminate\Database\Eloquent\Model;

/**
 * A URL that gets events (conversations and customers in the API's JSON).
 */
class Webhook extends Model
{
    public $timestamps = false;

    /**
     * Events, by when they happen.
     */
    const EVENTS = [
        'convo.created',
        'convo.assigned',
        'convo.status',
        'convo.moved',
        'convo.customer.reply.created',
        'convo.agent.reply.created',
        'convo.note.created',
        'convo.deleted',
        'convo.deleted_forever',
        'convo.restored',
        'customer.created',
        'customer.updated',
    ];

    /**
     * Failed deliveries are tried this many times in all.
     */
    const MAX_ATTEMPTS = 10;

    protected $casts = [
        'events'        => 'array',
        'mailboxes'     => 'array',
        'last_run_time' => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(WebhookLog::class);
    }

    /**
     * Events webhooks can be for: the built-in ones and ones that modules
     * (e.g. Workflows) add.
     */
    public static function allEvents()
    {
        return array_values(array_unique(\Eventy::filter('webhooks.events', self::EVENTS)));
    }

    /**
     * The secret deliveries are signed with.
     */
    public static function secret()
    {
        return md5(config('app.key').'webhook_key');
    }

    /**
     * X-FreeScout-Signature: base64 of the HMAC-SHA1 of the body.
     */
    public static function sign($body)
    {
        return base64_encode(hash_hmac('sha1', $body, self::secret(), true));
    }

    /**
     * Whether the webhook wants this event about this entity.
     */
    public function wants($event, $entity)
    {
        if (!in_array($event, (array) $this->events)) {
            return false;
        }
        if ($entity instanceof Conversation && $this->mailboxes) {
            return in_array($entity->mailbox_id, array_map('intval', $this->mailboxes));
        }

        return true;
    }
}
