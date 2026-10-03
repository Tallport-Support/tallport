<?php

namespace App\Api;

use App\Conversation;
use App\Customer;
use App\User;

/**
 * What the key of an API request may do: the global key everything; a
 * user's key what its owner may, in the key's mailboxes, as the owner
 * (an administrator's key also on behalf of other users).
 */
class ApiAccess
{
    /**
     * The user's key; null for the global key.
     */
    public $key = null;

    public function __construct(?ApiKey $key = null)
    {
        $this->key = $key;
    }

    public static function current()
    {
        return request()->attributes->get('api_access') ?: new self();
    }

    public function isGlobal()
    {
        return $this->key === null;
    }

    public function user()
    {
        return $this->key ? $this->key->user : null;
    }

    /**
     * The global key, or a key of an administrator.
     */
    public function isAdmin()
    {
        return $this->isGlobal() || ($this->user() && $this->user()->isAdmin());
    }

    /**
     * Mailbox IDs the key may use; null: all.
     */
    public function mailboxIds()
    {
        return $this->key ? $this->key->allowedMailboxIds() : null;
    }

    public function canMailbox($mailbox_id)
    {
        return $this->isGlobal() || in_array((int) $mailbox_id, $this->mailboxIds());
    }

    public function canConversation(Conversation $conversation)
    {
        return $this->isGlobal()
            || ($this->canMailbox($conversation->mailbox_id) && $this->user()->can('view', $conversation));
    }

    public function canCustomer(Customer $customer)
    {
        return $this->isGlobal() || $this->user()->can('view', $customer);
    }

    /**
     * Whether the key may act as this user: a user's key as its owner, an
     * administrator's key as anyone.
     */
    public function canActAs($user_id)
    {
        return $this->isAdmin() || (int) $user_id === (int) $this->user()->id;
    }
}
