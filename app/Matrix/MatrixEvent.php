<?php

namespace App\Matrix;

use Illuminate\Database\Eloquent\Model;

class MatrixEvent extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected $casts = ['payload' => EncryptedJson::class, 'retry_at' => 'datetime'];

    public static function outgoing($identity, $key, $kind, array $payload, $room = null, $thread = null)
    {
        return self::firstOrCreate(['matrix_mailbox_id' => $identity, 'local_key' => hash('sha256', $key)], [
            'kind' => $kind, 'payload' => $payload, 'room_id' => $room, 'thread_id' => $thread,
            'transaction_id' => bin2hex(random_bytes(16)), 'status' => 'pending',
        ]);
    }
}
