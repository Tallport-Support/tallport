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
     * A request waiting for the AI sends nothing while it works: $send now and then (each 15
     * seconds, the first after 15) keeps it open, as proxies close quiet ones (Cloudflare after
     * 100 seconds). What this returns is called on each sign of the AI's work.
     */
    public static function keepAlive(callable $send)
    {
        $heartbeat = new self(15);
        $heartbeat->ready();

        return function () use ($heartbeat, $send) {
            if ($heartbeat->ready()) {
                $send();
            }
        };
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
