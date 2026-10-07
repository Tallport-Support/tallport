<?php

namespace App\Ai;

/**
 * How often an answer being written is passed on (to the browser or the realtime channel):
 * at most once per interval, so that a stream of small pieces doesn't flood it.
 */
class StreamThrottle
{
    public $interval;

    protected $last = null;

    /**
     * At most once per $interval seconds.
     */
    public function __construct($interval)
    {
        $this->interval = $interval;
    }

    /**
     * Whether it's time to pass the answer on (and it's done now).
     */
    public function ready()
    {
        // In microseconds.
        $now = (int) now()->format('Uu');
        if ($this->last !== null && $now - $this->last < (int) round($this->interval * 1000000)) {
            return false;
        }
        $this->last = $now;

        return true;
    }
}
