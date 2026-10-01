<?php
/*
* File:     FolderNewEvent.php
* Category: Event
* Author:   M. Goldenbaum
* Created:  25.11.20 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace App\LegacyImap\Events;

use App\LegacyImap\Folder;

/**
 * Class FolderNewEvent
 *
 * @package App\LegacyImap\Events
 */
class FolderNewEvent extends Event {

    /** @var Folder $folder */
    public $folder;

    /**
     * Create a new event instance.
     * @var Folder[] $folders
     * @return void
     */
    public function __construct($folders) {
        $this->folder = $folders[0];
    }
}
