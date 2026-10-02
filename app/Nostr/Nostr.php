<?php

namespace App\Nostr;

use App\Customer;

/**
 * Nostr as a channel: a mailbox's Nostr identity receives customers' NIP-17
 * private messages (conversations of the chat type) and agents' replies are
 * sent back, end-to-end encrypted. Settings are in nostr_mailboxes.
 */
class Nostr
{
    /**
     * Customer channel and conversation channel.
     */
    public static function channel()
    {
        return (int) config('nostr.channel');
    }

    public static function isNostr($conversation)
    {
        return $conversation && $conversation->isChat() && (int) $conversation->channel === self::channel();
    }

    /**
     * Fill in the name and picture of an auto-created customer from their kind 0 profile,
     * and cache their DM relays for later replies.
     */
    public static function fetchProfile($customer_id, $pubkey, $cfg_id)
    {
        $customer = Customer::find($customer_id);
        $cfg = NostrMailbox::find($cfg_id);
        if (!$customer || !$cfg || !$cfg->getPrivateKey()) {
            return;
        }

        $client = new RelayClient(RelayClient::authSignerForKey($cfg->getPrivateKey()), self::logger());
        $discovery = new RelayDiscovery($client);
        $relays = $cfg->getAllRelays();

        $profile = $discovery->profile($pubkey, $relays);
        $dmRelays = $discovery->dmRelays($pubkey, $relays);

        $key = CustomerKey::byPubkey($pubkey);
        if ($key) {
            if ($profile) {
                $key->setProfile($profile);
            }
            $key->setDmRelays($dmRelays);
            $key->save();
        }

        if (!$profile) {
            return;
        }

        $name = trim((string) ($profile['display_name'] ?? '')) ?: trim((string) ($profile['name'] ?? ''));
        if ($name !== '' && $customer->first_name === Keys::shortNpub($pubkey)) {
            $parts = preg_split('/\s+/', $name, 2);
            $customer->first_name = mb_substr($parts[0], 0, 255);
            $customer->last_name = isset($parts[1]) ? mb_substr($parts[1], 0, 255) : null;
            $customer->save();
        }

        if (!empty($profile['picture']) && !$customer->photo_url && preg_match('#^https?://#i', $profile['picture'])) {
            try {
                $customer->setPhotoFromRemoteFile($profile['picture']);
            } catch (\Throwable $e) {
                // A missing avatar is not a problem.
            }
        }
    }

    /**
     * Logger used outside the console: writes to the Laravel log.
     */
    public static function logger()
    {
        return function ($message) {
            \Log::info('[Nostr] '.$message);
        };
    }
}
