<?php

namespace App\Matrix;

use Illuminate\Database\Eloquent\Model;

class MatrixMailbox extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['credentials', 'sync_token'];
    protected $casts = ['credentials' => 'encrypted:array', 'sync_token' => 'encrypted', 'last_synced_at' => 'datetime'];

    public function mailbox()
    {
        return $this->belongsTo(\App\Mailbox::class);
    }

    public static function forMailbox($id)
    {
        return self::where('active_mailbox_id', $id)->first();
    }

    public function isReady()
    {
        return $this->active_mailbox_id !== null && in_array($this->status, ['ready', 'verification'], true);
    }

    public function isVerified()
    {
        return $this->active_mailbox_id !== null && $this->status === 'ready';
    }

    public function client()
    {
        return new Client($this->homeserver, $this->credentials['access_token'] ?? '');
    }

    public static function withLock($mailbox_id, $callback)
    {
        return \Cache::lock('matrix:mailbox:'.$mailbox_id, 330)->block(2, function () use ($callback) {
            Client::$deadline = microtime(true) + 270;
            try {
                return $callback();
            } finally {
                Client::$deadline = null;
            }
        });
    }

    public function locked($callback)
    {
        return self::withLock($this->mailbox_id, function () use ($callback) {
            $this->refresh();

            return $callback($this);
        });
    }
}
