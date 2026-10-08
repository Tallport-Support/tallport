<?php

namespace App\Ai;

use Illuminate\Http\Client\RequestException;

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
}
