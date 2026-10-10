<?php

namespace App\Matrix;

use App\Matrix\Crypto\CanonicalJson;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

/**
 * Preserve JSON objects in signed Matrix content across persistence.
 */
class EncryptedJson implements CastsAttributes
{
    public function get($model, $key, $value, $attributes)
    {
        return $value === null ? null : CanonicalJson::decode(Crypt::decryptString($value));
    }

    public function set($model, $key, #[\SensitiveParameter] $value, $attributes)
    {
        return $value === null ? null : Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
