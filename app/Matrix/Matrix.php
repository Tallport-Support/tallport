<?php

namespace App\Matrix;

class Matrix
{
    const CHANNEL = 91;

    public static function isMatrix($conversation)
    {
        return $conversation && (int) $conversation->channel === self::CHANNEL;
    }
}
