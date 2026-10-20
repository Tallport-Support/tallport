<?php

namespace App\WebChat;

use App\Customer;
use App\Email;
use App\Mailbox;

/**
 * Visitors signed in on the website: its server signs who they are with the widget's secret, a
 * short-lived JWT (HS256) with sub (the website's account ID), and optionally email, name and data
 * (what the team may see, such as the plan), and exp. The page passes it on with
 * tallport('identify', token). Only a valid signature counts; nothing is matched without one.
 */
class Identity
{
    /**
     * How far ahead a token may expire, in seconds: they are meant to be made per page.
     */
    const MAX_LIFETIME = 86400;

    /**
     * Clock difference allowed between the website's server and Tallport, in seconds.
     */
    const LEEWAY = 60;

    /**
     * What the team sees of `data`: at most this many values, each this long.
     */
    const MAX_DATA = 20;
    const MAX_DATA_LENGTH = 200;

    /**
     * The claims of a valid token: sub, email, name, data; null when it isn't one.
     */
    public static function verify($token, $secret)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) != 3 || (string) $secret === '') {
            return null;
        }
        [$header, $payload, $signature] = $parts;
        $header_data = json_decode((string) self::decode($header), true);
        if (!is_array($header_data) || ($header_data['alg'] ?? null) !== 'HS256') {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', $header.'.'.$payload, $secret, true), (string) self::decode($signature))) {
            return null;
        }
        $claims = json_decode((string) self::decode($payload), true);
        if (!is_array($claims)) {
            return null;
        }
        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) || $exp < time() - self::LEEWAY || $exp > time() + self::MAX_LIFETIME) {
            return null;
        }
        if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > time() + self::LEEWAY)) {
            return null;
        }
        $sub = $claims['sub'] ?? null;
        if (!(is_string($sub) || is_int($sub)) || trim((string) $sub) === '') {
            return null;
        }
        $email = Email::sanitizeEmail((string) ($claims['email'] ?? ''));

        return [
            'sub'   => trim((string) $sub),
            'email' => $email ?: null,
            'name'  => mb_substr(trim((string) ($claims['name'] ?? '')), 0, 100),
            'data'  => self::data($claims['data'] ?? []),
        ];
    }

    /**
     * A JWT signed with the secret (tests, and the settings page's example).
     */
    public static function sign(array $claims, $secret)
    {
        $header = self::encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::encode(json_encode($claims));

        return $header.'.'.$payload.'.'.self::encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));
    }

    /**
     * The customer the website says this is: by their account ID (sub) first, then by the
     * address it vouched for; else a new customer. Their account ID is remembered either way.
     */
    public static function customer(array $identity)
    {
        $channel_id = self::channelId($identity['sub']);
        $customer = Customer::getCustomerByChannel(WebChat::CHANNEL, $channel_id);
        if (!$customer && $identity['email']) {
            $customer = Customer::getByEmail($identity['email']);
        }
        if (!$customer) {
            $names = preg_split('/\s+/u', $identity['name'], 2);
            $customer = Customer::createWithoutEmail(['first_name' => $names[0] ?? '', 'last_name' => $names[1] ?? '']);
        } elseif ($identity['name'] !== '' && !$customer->first_name && !$customer->last_name) {
            $names = preg_split('/\s+/u', $identity['name'], 2);
            $customer->first_name = $names[0];
            $customer->last_name = $names[1] ?? '';
            $customer->save();
        }
        if ($identity['email']) {
            $customer = WebChat::takeEmail($customer, $identity['email']);
        }
        if ($customer && !Customer::getCustomerByChannel(WebChat::CHANNEL, $channel_id)) {
            $customer->addChannel(WebChat::CHANNEL, $channel_id);
        }

        return $customer;
    }

    /**
     * The customer channel ID of a website account: "s:" and its ID (hashed when long).
     */
    public static function channelId($sub)
    {
        return strlen($sub) <= 60 ? 's:'.$sub : 'h:'.hash('sha256', $sub);
    }

    /**
     * A widget's signing secret (WebChat::secret()).
     */
    public static function secret(Mailbox $mailbox, $rotate = false)
    {
        return WebChat::secret($mailbox, 'identity_secret', $rotate);
    }

    public static function hasSecret(Mailbox $mailbox)
    {
        return WebChat::hasSecret($mailbox, 'identity_secret');
    }

    /**
     * What the team may see: names and simple values, a few, short.
     */
    protected static function data($data)
    {
        $result = [];
        foreach (is_array($data) ? $data : [] as $name => $value) {
            if (count($result) >= self::MAX_DATA || !is_string($name) || !(is_scalar($value) || $value === null)) {
                continue;
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $result[mb_substr($name, 0, 50)] = mb_substr($value, 0, self::MAX_DATA_LENGTH);
        }

        return $result;
    }

    protected static function decode($value)
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    protected static function encode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
