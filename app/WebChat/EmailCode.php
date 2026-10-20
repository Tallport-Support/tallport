<?php

namespace App\WebChat;

use Illuminate\Support\Facades\RateLimiter;

/**
 * "Get the reply by email": an anonymous visitor's address counts only once they confirm it with a
 * 6-digit code sent there, so a mistyped or someone else's address never gets their conversation.
 * The email holds only the code and the team's name, nothing the visitor typed.
 */
class EmailCode
{
    /**
     * Codes a visitor can ask for (Send, then Send Again twice), wrong tries per code, and how long
     * a code (and the address waiting for it) lasts, in minutes.
     */
    const MAX_CODES = 3;
    const MAX_ATTEMPTS = 5;
    const LIFETIME = 60;

    /**
     * Codes per address per day, and per IP address per hour.
     */
    const MAX_PER_ADDRESS = 5;
    const MAX_PER_IP = 10;

    /**
     * Send a code to the address; an error message (for the visitor) when it can't be.
     */
    public static function send(Visitor $visitor, $email, $ip)
    {
        $email = \App\Email::sanitizeEmail((string) $email);
        if (!$email) {
            return __('Enter an email address, like name@example.com.');
        }
        if (!$visitor->customer_id) {
            return __('Write a message first.');
        }
        // Another address, or the last code expired: counting starts again (the limits per address and IP stay).
        if ($visitor->pending_email !== $email || !self::waiting($visitor)) {
            $visitor->codes_sent = 0;
        }
        $limits = ['web-chat:code-address:'.sha1($email) => [self::MAX_PER_ADDRESS, 86400], 'web-chat:code-ip:'.$ip => [self::MAX_PER_IP, 3600]];
        if ($visitor->codes_sent >= self::MAX_CODES || collect($limits)->contains(fn ($limit, $key) => RateLimiter::tooManyAttempts($key, $limit[0]))) {
            return __('You can ask for another code later.');
        }
        foreach ($limits as $key => $limit) {
            RateLimiter::hit($key, $limit[1]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $visitor->pending_email = $email;
        $visitor->code_hash = self::hash($visitor, $code);
        $visitor->code_expires_at = now()->addMinutes(self::LIFETIME);
        $visitor->codes_sent++;
        $visitor->code_attempts = 0;
        $visitor->save();

        \App\Jobs\SendWebChatCode::dispatch($visitor->id, $email, $code, app()->getLocale())->onQueue('emails');

        return null;
    }

    /**
     * Confirm the address with its code: it becomes the customer's (or, when it's another
     * customer's, they're the same person and their conversations come together). An error
     * message when the code isn't right.
     */
    public static function confirm(Visitor $visitor, $code)
    {
        $code = preg_replace('/\D/', '', (string) $code);
        if (!self::waiting($visitor) || $visitor->code_attempts >= self::MAX_ATTEMPTS) {
            return __('That code has expired. Ask for a new one.');
        }
        if (strlen($code) != 6 || !hash_equals((string) $visitor->code_hash, self::hash($visitor, $code))) {
            $visitor->code_attempts++;
            $visitor->save();

            return __('That code isn’t right. Check the email and try again.');
        }

        \DB::transaction(function () use ($visitor) {
            $email = $visitor->pending_email;
            $customer = WebChat::takeEmail($visitor->customer, $email);
            $visitor->customer_id = $customer->id;
            self::forget($visitor);
            $visitor->save();
            if ($visitor->conversation && !$visitor->conversation->customer_email) {
                $visitor->conversation->customer_email = $email;
                $visitor->conversation->save();
            }
        });

        return null;
    }

    /**
     * Whether a code is waiting to be entered.
     */
    public static function waiting(Visitor $visitor)
    {
        return $visitor->pending_email && $visitor->code_expires_at && $visitor->code_expires_at->isFuture();
    }

    public static function forget(Visitor $visitor)
    {
        $visitor->pending_email = null;
        $visitor->code_hash = null;
        $visitor->code_expires_at = null;
        $visitor->codes_sent = 0;
        $visitor->code_attempts = 0;
    }

    /**
     * Unconfirmed addresses are forgotten once their code has expired (the scheduler, hourly).
     */
    public static function forgetExpired()
    {
        return Visitor::whereNotNull('pending_email')->where('code_expires_at', '<', now())
            ->update(['pending_email' => null, 'code_hash' => null, 'code_expires_at' => null, 'codes_sent' => 0, 'code_attempts' => 0]);
    }

    protected static function hash(Visitor $visitor, $code)
    {
        return hash_hmac('sha256', $visitor->id.'|'.$visitor->pending_email.'|'.$code, config('app.key'));
    }
}
