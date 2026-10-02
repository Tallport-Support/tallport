<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A mailbox's auto reply in a language, sent instead of the mailbox's
 * (default) auto reply to customers who write in that language.
 */
class MailboxAutoReply extends Model
{
    protected $table = 'mailbox_auto_replies';

    protected $fillable = ['mailbox_id', 'language'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }
}
