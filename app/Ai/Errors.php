<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\StreamErrorException;

/**
 * AI failures: one reference in the operator log and in the safe message shown to users.
 */
class Errors
{
    protected static $references;

    public static function report(\Throwable $e, $context)
    {
        self::$references ??= new \WeakMap();
        if (isset(self::$references[$e])) {
            return self::$references[$e];
        }

        $reference = strtoupper(bin2hex(random_bytes(6)));
        $status = $e instanceof RequestException ? $e->response->status() : (int) $e->getCode();
        $detail = get_class($e).($status >= 400 && $status <= 599 ? ' HTTP '.$status : '').': '.$e->getMessage();
        \Log::error('[AI] ['.$reference.'] '.Usage::redact(trim($context.' '.$detail)));
        self::$references[$e] = $reference;

        return $reference;
    }

    public static function message(\Throwable $e, $context, $message = null)
    {
        return ($message ?: __('Error occurred')).' ('.__('ID').': '.self::report($e, $context).')';
    }

    /**
     * Whether a failure was the provider's being unavailable (down, overloaded, rate limited,
     * too slow: an error page from the proxy in front of it, as Cloudflare's), not its answer:
     * worth trying again later.
     */
    public static function unavailable(\Throwable $e)
    {
        if ($e instanceof FailoverableException || $e instanceof ConnectionException || $e instanceof StreamErrorException
            || $e instanceof \GuzzleHttp\Exception\TransferException
        ) {
            return true;
        }
        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return in_array($status, [408, 425, 429]) || $status >= 500;
        }

        return $e->getMessage() === __('The AI took too long to answer.');
    }
}
