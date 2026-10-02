<?php

namespace App\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * A mailbox's key for pushing documents through the documentation API.
 * Only its hash is stored.
 */
class DocumentApiKey extends Model
{
    protected $table = 'aiassistant_mailbox_api_keys';

    protected $fillable = ['mailbox_id'];

    protected $casts = [
        'enabled'      => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }

    /**
     * A new key for a mailbox (replacing its old one). Returns the key.
     */
    public static function issue($mailbox_id)
    {
        $token = 'fsai_'.bin2hex(random_bytes(32));
        $key = self::firstOrNew(['mailbox_id' => $mailbox_id]);
        $key->mailbox_id = $mailbox_id;
        $key->key_hash = self::hash($token);
        $key->key_preview = substr($token, 0, 6).'...'.substr($token, -6);
        $key->enabled = true;
        $key->save();

        return $token;
    }

    public static function findByToken($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        return self::where('key_hash', self::hash($token))->where('enabled', true)->first();
    }

    protected static function hash($token)
    {
        return hash('sha256', trim($token));
    }
}
