<?php

namespace App\Matrix;

use Illuminate\Database\Eloquent\Model;

class MatrixRoom extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['state'];
    protected $casts = ['state' => 'encrypted:array'];

    public static function forRoom($identity, $room)
    {
        return self::firstOrNew(['matrix_mailbox_id' => $identity, 'room_hash' => hash('sha256', $room)], ['room_id' => $room, 'state' => []]);
    }

    public function conversation()
    {
        return $this->belongsTo(\App\Conversation::class);
    }
}
