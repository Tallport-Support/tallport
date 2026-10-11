<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Watson\Rememberable\Rememberable;

class MailboxUser extends Model
{
    use Rememberable;
    // This is obligatory.
    public $rememberCacheDriver = 'array';
    
    protected $table = 'mailbox_user';

    public $timestamps = false;

    public static $pivot_settings = [
        'hide',
        'mute',
        'access',
    ];

    // Does not work as we receive it via pivot
    // protected $casts = [
    //     'access' => 'array',
    // ];
}
