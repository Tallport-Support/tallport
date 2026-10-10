<?php

namespace App\Matrix;

use Illuminate\Database\Eloquent\Model;

class CryptoRecord extends Model
{
    protected $table = 'matrix_crypto';
    protected $guarded = ['id'];
    protected $hidden = ['value'];
    protected $casts = ['value' => EncryptedJson::class];

    public static function record($identity, $kind, $key)
    {
        return self::firstOrNew(['matrix_mailbox_id' => $identity, 'kind' => $kind, 'key_hash' => hash('sha256', $key)]);
    }

    public static function read($identity, $kind, $key)
    {
        return self::record($identity, $kind, $key)->value;
    }

    public static function write($identity, $kind, $key, #[\SensitiveParameter] array $value)
    {
        $record = self::record($identity, $kind, $key);
        $record->value = $value;
        $record->save();

        return $record;
    }
}
