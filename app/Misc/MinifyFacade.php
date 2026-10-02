<?php

namespace App\Misc;

use Illuminate\Support\Facades\Facade;

/**
 * \Minify: App\Misc\Minify.
 */
class MinifyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'minify';
    }
}
