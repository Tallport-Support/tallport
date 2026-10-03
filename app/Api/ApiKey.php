<?php

namespace App\Api;

use App\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's key for the REST API: acts as that user, read only or read and
 * write, optionally for some of the user's mailboxes. Only a hash is kept.
 */
class ApiKey extends Model
{
    const ABILITY_READ = 1;
    const ABILITY_WRITE = 2;

    /**
     * Keys look like fs_ and 40 hex characters.
     */
    const PREFIX = 'fs_';

    protected $casts = [
        'ability'      => 'integer',
        'mailboxes'    => 'array',
        'last_used_at' => 'datetime',
    ];

    protected $allowed_mailbox_ids = null;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a key. Returns [ApiKey, the key to show once].
     */
    public static function generate(User $user, $name, $ability, $mailbox_ids = null)
    {
        $token = self::PREFIX.bin2hex(random_bytes(20));

        $key = new self();
        $key->user_id = $user->id;
        $key->name = mb_substr(trim((string) $name), 0, 255);
        $key->key_hash = hash('sha256', $token);
        $key->token_preview = substr($token, -6);
        $key->ability = (int) $ability == self::ABILITY_WRITE ? self::ABILITY_WRITE : self::ABILITY_READ;
        if ($mailbox_ids) {
            // Only mailboxes the user has.
            $mailbox_ids = array_values(array_intersect(array_map('intval', (array) $mailbox_ids), $user->mailboxesIdsCanView()));
        }
        $key->mailboxes = $mailbox_ids ?: null;
        $key->save();

        return [$key, $token];
    }

    public static function findByToken($token)
    {
        $token = (string) $token;
        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }

        return self::where('key_hash', hash('sha256', $token))->first();
    }

    /**
     * The global API key: unrestricted. Derived from the application key
     * and config('api.key_salt'), never stored.
     */
    public static function globalKey()
    {
        return md5(config('app.key').'api_key'.config('api.key_salt'));
    }

    public function canWrite()
    {
        return $this->ability === self::ABILITY_WRITE;
    }

    /**
     * Mailboxes the key may use: the owner's, limited to the key's.
     */
    public function allowedMailboxIds()
    {
        if ($this->allowed_mailbox_ids === null) {
            $ids = $this->user ? $this->user->mailboxesIdsCanView() : [];
            if ($this->mailboxes) {
                $ids = array_intersect($ids, array_map('intval', $this->mailboxes));
            }
            $this->allowed_mailbox_ids = array_values(array_map('intval', $ids));
        }

        return $this->allowed_mailbox_ids;
    }

    /**
     * Remember when the key was used (at most every 5 minutes).
     */
    public function markUsed()
    {
        if (!$this->last_used_at || $this->last_used_at->lt(now()->subMinutes(5))) {
            $this->last_used_at = now();
            $this->save();
        }
    }
}
