<?php

namespace App\WebChat;

use App\Conversation;
use App\Mailbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keeping the website chat from being a way to fill the database or load the server: nothing is
 * stored until someone writes; hourly limits per widget on new chats, messages and uploads, and per
 * visitor and address; a small proof of work before a new chat (no third parties); and blocking.
 * Over a limit, the chat button goes (new chats) or the window says how long to wait.
 */
class Spam
{
    /**
     * Per widget per hour (settings, Mailbox » Website Chat): new chats, messages, megabytes of files.
     */
    const LIMITS = ['new_chats' => 30, 'messages' => 300, 'uploads_mb' => 200];

    /**
     * Per visitor: messages a minute and an hour; megabytes of files an hour (also per address).
     */
    const VISITOR_PER_MINUTE = 6;
    const VISITOR_PER_HOUR = 60;
    const VISITOR_UPLOADS_MB = 50;

    /**
     * Proof of work before a new chat: leading zero bits of SHA-256(salt:nonce). Normally well under a
     * second; more as the widget nears its limit, or when a signal says it's a bot (a first message
     * within FAST_MS of the window loading).
     */
    const DIFFICULTY = 14;
    const MAX_DIFFICULTY = 20;
    const FAST_MS = 2000;
    const CHALLENGE_SECONDS = 600;

    /**
     * How long blocking an address lasts, in seconds.
     */
    const BLOCK_IP_SECONDS = 86400;

    public static function limits(Mailbox $mailbox)
    {
        $settings = (array) ($mailbox->meta[WebChat::META] ?? []);
        $limits = [];
        foreach (self::LIMITS as $name => $default) {
            $limits[$name] = max(1, (int) ($settings['limit_'.$name] ?? $default));
        }

        return $limits;
    }

    /**
     * Whether new chats are taken now (the widget's limit for the hour isn't reached).
     */
    public static function available(Mailbox $mailbox)
    {
        return !RateLimiter::tooManyAttempts(self::key('new-chats', $mailbox->id), self::limits($mailbox)['new_chats']);
    }

    public static function countNewChat(Mailbox $mailbox)
    {
        RateLimiter::hit(self::key('new-chats', $mailbox->id), 3600);
    }

    /**
     * A message (with $kilobytes of files) may go now; else the seconds to wait and which limit
     * ('messages' or 'files'). Counted when it may.
     */
    public static function allowMessage(Visitor $visitor, $ip_hash, $kilobytes)
    {
        $limits = self::limits($visitor->mailbox);
        $id = $visitor->token;
        $messages = [
            self::key('visitor-minute', $id)          => [self::VISITOR_PER_MINUTE, 60],
            self::key('visitor-hour', $id)            => [self::VISITOR_PER_HOUR, 3600],
            self::key('messages', $visitor->mailbox_id) => [$limits['messages'], 3600],
        ];
        $files = $kilobytes ? [
            self::key('uploads-visitor', $id)              => [self::VISITOR_UPLOADS_MB * 1024, 3600],
            self::key('uploads-ip', $ip_hash)              => [self::VISITOR_UPLOADS_MB * 1024, 3600],
            self::key('uploads', $visitor->mailbox_id)     => [$limits['uploads_mb'] * 1024, 3600],
        ] : [];
        foreach (['messages' => $messages, 'files' => $files] as $kind => $keys) {
            foreach ($keys as $key => [$max, $decay]) {
                $used = (int) RateLimiter::attempts($key);
                if ($used + ($kind == 'files' ? $kilobytes : 1) > $max && $used > 0) {
                    return [max(1, RateLimiter::availableIn($key)), $kind];
                }
            }
        }
        foreach ($messages as $key => [$max, $decay]) {
            RateLimiter::hit($key, $decay);
        }
        foreach ($files as $key => [$max, $decay]) {
            RateLimiter::increment($key, $decay, $kilobytes);
        }

        return null;
    }

    /**
     * A visitor token that needs no row until they write: random, signed for the widget.
     */
    public static function newToken(Mailbox $mailbox)
    {
        $random = \Str::random(40);

        return $random.'.'.self::sign($mailbox->id.'|'.$random, 24);
    }

    public static function validToken($token, Mailbox $mailbox)
    {
        $parts = explode('.', (string) $token);

        return count($parts) == 2 && strlen($parts[0]) == 40 && hash_equals(self::sign($mailbox->id.'|'.$parts[0], 24), $parts[1]);
    }

    /**
     * The proof of work asked for now, at least $at_least: harder as the widget nears its limit.
     */
    public static function difficulty(Mailbox $mailbox, $at_least = 0)
    {
        $limit = self::limits($mailbox)['new_chats'];
        $used = (int) RateLimiter::attempts(self::key('new-chats', $mailbox->id));
        $difficulty = self::DIFFICULTY + ($used >= $limit * 0.5 ? 2 : 0) + ($used >= $limit * 0.8 ? 2 : 0);

        return min(self::MAX_DIFFICULTY, max($difficulty, (int) $at_least));
    }

    /**
     * A challenge: the salt to solve, its difficulty and expiry, signed (no row, no cache).
     */
    public static function challenge(Mailbox $mailbox, $at_least = 0)
    {
        $payload = $mailbox->id.'.'.bin2hex(random_bytes(16)).'.'.self::difficulty($mailbox, $at_least).'.'.(time() + self::CHALLENGE_SECONDS);

        return ['challenge' => $payload.'.'.self::sign($payload, 32), 'difficulty' => (int) explode('.', $payload)[2]];
    }

    /**
     * Whether "challenge:nonce" solves a challenge for the widget, at $required difficulty at least,
     * and is used for the first time.
     */
    public static function solved($proof, Mailbox $mailbox, $required)
    {
        $parts = explode(':', (string) $proof);
        $challenge = explode('.', $parts[0] ?? '');
        if (count($parts) != 2 || count($challenge) != 5 || !ctype_digit($parts[1])) {
            return false;
        }
        [$mailbox_id, $salt, $difficulty, $expires, $signature] = $challenge;
        if ($mailbox_id != $mailbox->id || (int) $expires < time() || (int) $difficulty < $required
            || !hash_equals(self::sign(implode('.', array_slice($challenge, 0, 4)), 32), $signature)
            || self::zeroBits(hash('sha256', $salt.':'.$parts[1], true)) < (int) $difficulty) {
            return false;
        }

        return Cache::add(self::key('proof', $salt), true, self::CHALLENGE_SECONDS);
    }

    /**
     * The address as kept: a keyed hash.
     */
    public static function ipHash($ip)
    {
        return hash_hmac('sha256', (string) $ip, config('app.key'));
    }

    /**
     * Blocked: the visitor, the customer (in the website chat) or the address (for a day).
     */
    public static function blocked(Visitor $visitor, $ip_hash)
    {
        return $visitor->blocked_at || ($visitor->customer && $visitor->customer->getMeta('web_chat_blocked'))
            || Cache::has(self::key('blocked-ip', $ip_hash));
    }

    /**
     * Block a website conversation's visitor: their tokens, the customer in the website chat, and
     * their address for a day. Their messages are taken and dropped.
     */
    public static function block(Conversation $conversation)
    {
        foreach (Visitor::where('conversation_id', $conversation->id)->get() as $visitor) {
            $visitor->blocked_at = now();
            $visitor->save();
            if ($visitor->ip_hash) {
                Cache::put(self::key('blocked-ip', $visitor->ip_hash), true, self::BLOCK_IP_SECONDS);
            }
        }
        if ($conversation->customer) {
            $conversation->customer->setMeta('web_chat_blocked', true);
            $conversation->customer->save();
        }
    }

    public static function unblock(Conversation $conversation)
    {
        foreach (Visitor::where('conversation_id', $conversation->id)->get() as $visitor) {
            $visitor->blocked_at = null;
            $visitor->save();
            if ($visitor->ip_hash) {
                Cache::forget(self::key('blocked-ip', $visitor->ip_hash));
            }
        }
        if ($conversation->customer) {
            $conversation->customer->setMeta('web_chat_blocked', null);
            $conversation->customer->save();
        }
    }

    public static function isBlocked(Conversation $conversation)
    {
        return Visitor::where('conversation_id', $conversation->id)->whereNotNull('blocked_at')->exists();
    }

    protected static function zeroBits($binary)
    {
        $bits = 0;
        foreach (str_split($binary) as $byte) {
            $byte = ord($byte);
            if ($byte == 0) {
                $bits += 8;
                continue;
            }
            while (($byte & 0x80) == 0) {
                $bits++;
                $byte <<= 1;
            }
            break;
        }

        return $bits;
    }

    protected static function sign($value, $length)
    {
        return substr(hash_hmac('sha256', 'web-chat|'.$value, config('app.key')), 0, $length);
    }

    protected static function key($name, $id)
    {
        return 'web-chat:'.$name.':'.$id;
    }
}
