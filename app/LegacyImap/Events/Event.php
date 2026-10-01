<?php
/*
* File:     Event.php
* Category: Event
* Author:   M. Goldenbaum
* Created:  25.11.20 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace App\LegacyImap\Events;

/**
 * Class Event
 *
 * @package App\LegacyImap\Events
 */
abstract class Event {

    /**
     * Dispatch the event with the given arguments.
     */
    public static function dispatch(): Event {
        return new static(func_get_args());
    }
}
