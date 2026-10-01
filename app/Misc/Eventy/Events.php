<?php

namespace App\Misc\Eventy;

/**
 * Eventy (the \Eventy hooks modules use) with listeners kept per hook.
 * Bound as "eventy" by AppServiceProvider.
 */
class Events extends \TorMorten\Eventy\Events
{
    public function __construct()
    {
        $this->action = new Action();
        $this->filter = new Filter();
    }
}
