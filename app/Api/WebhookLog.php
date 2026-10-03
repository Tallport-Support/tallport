<?php

namespace App\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A delivery that failed: tried again until it succeeds or gives up.
 * Kept for 3 days after that (model:prune).
 */
class WebhookLog extends Model
{
    use Prunable;

    protected $casts = [
        'finished' => 'boolean',
        'attempts' => 'integer',
    ];

    public function webhook()
    {
        return $this->belongsTo(Webhook::class);
    }

    public function prunable()
    {
        return static::where('finished', true)->where('updated_at', '<', now()->subDays(3));
    }

    public function isSuccess()
    {
        return $this->status_code >= 200 && $this->status_code < 300;
    }
}
